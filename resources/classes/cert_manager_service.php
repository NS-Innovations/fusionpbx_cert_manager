<?php
/*
 FusionPBX
 Version: MPL 1.1

 The contents of this file are subject to the Mozilla Public License Version
 1.1 (the "License"); you may not use this file except in compliance with
 the License. You may obtain a copy of the License at
 http://www.mozilla.org/MPL/

 Software distributed under the License is distributed on an "AS IS" basis,
 WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 for the specific language governing rights and limitations under the
 License.

 The Original Code is FusionPBX

 The Initial Developer of the Original Code is
 Mark J Crane <markjcrane@fusionpbx.com>
 Portions created by the Initial Developer are Copyright (C) 2008-2026
 the Initial Developer. All Rights Reserved.

 Contributor(s):
 Mark J Crane <markjcrane@fusionpbx.com>
 Norman King <norman@nsinnovations.net>
*/

/**
 * Orders and renews the certificates.
 *
 * Each pass through the loop takes every certificate that needs it one step further: a new certificate,
 * one somebody asked to renew, one that is due for renewal, and one with an order in progress. A step never
 * waits long, so one slow order does not hold up the others; a manual DNS record can take days.
 *
 * A certificate that failed is not tried again before its next attempt, which backs off from an hour to a
 * day, unless somebody asks for it again from the web UI.
 */
class cert_manager_service extends service {

	/**
	 * database object
	 * @var database
	 */
	private $database;

	/**
	 * settings object
	 * @var settings
	 */
	private $settings;

	/**
	 * whether the service does any work
	 * @var bool
	 */
	private $enabled;

	/**
	 * seconds between passes
	 * @var int
	 */
	private $interval;

	/**
	 * days before it expires a certificate that does not set its own is renewed
	 * @var int
	 */
	private $renew_days;

	/**
	 * days of log to keep
	 * @var int
	 */
	private $log_retention_days;

	/**
	 * when the log was last trimmed
	 * @var int
	 */
	private $last_trim = 0;

	/**
	 * Reloads settings from the config file and the database.
	 *
	 * @return void
	 */
	public function reload_settings(): void {
		//re-read the config file to get any possible changes
		parent::$config->read();

		//connect to the database
		$this->database = new database(['config' => parent::$config]);

		//get the settings using the global defaults
		$this->settings = new settings(['database' => $this->database]);

		//get the service settings
		$this->enabled = $this->settings->get('cert_manager', 'enabled', true);
		$this->interval = (int) $this->settings->get('cert_manager', 'interval', 60);
		$this->renew_days = (int) $this->settings->get('cert_manager', 'renew_days', 30);
		$this->log_retention_days = (int) $this->settings->get('cert_manager', 'log_retention_days', 90);

		//keep the loop from spinning
		if ($this->interval < 10) {
			$this->interval = 10;
		}
	}

	/**
	 * Runs the service loop until the service is asked to stop.
	 *
	 * @return int The exit status.
	 */
	public function run(): int {

		//reload the settings
		$this->reload_settings();

		//service work is handled here
		while ($this->running) {

			//make sure the database connection is available
			while (!$this->database->is_connected()) {
				$this->database->connect();
				$this->settings = new settings(['database' => $this->database]);
				sleep(1);
			}

			//the service can be turned off without stopping it
			if ($this->enabled) {
				try {
					$this->order_certificates();
					$this->deploy_certificates();
					$this->trim_log();
				}
				catch (Throwable $e) {
					$this->error($e->getMessage());
				}
			}

			//pause to prevent excessive database queries
			sleep($this->interval);
		}
		return 0;
	}

	/**
	 * Takes every certificate that needs it one step further.
	 *
	 * @return void
	 */
	private function order_certificates(): void {
		$sql = "select c.certificate_uuid, c.certificate_name from v_certificates as c ";
		$sql .= "join v_acme_accounts as a on a.acme_account_uuid = c.acme_account_uuid ";
		$sql .= "where c.certificate_source = 'acme' ";
		$sql .= "and c.certificate_enabled = true ";
		$sql .= "and a.acme_account_enabled = true ";
		$sql .= "and (";
		$sql .= "	c.certificate_order is not null ";
		$sql .= "	or c.certificate_renew = true ";
		$sql .= "	or (";
		$sql .= "		(c.certificate_not_after is null or c.certificate_not_after < now() + make_interval(days => coalesce(c.certificate_renew_days, :renew_days)::int)) ";
		$sql .= "		and (c.certificate_next_attempt is null or c.certificate_next_attempt <= now()) ";
		$sql .= "	) ";
		$sql .= ") ";
		$sql .= "order by c.certificate_name asc ";
		$parameters['renew_days'] = $this->renew_days;
		$certificates = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (!is_array($certificates) || @sizeof($certificates) == 0) {
			return;
		}

		$crypto = new cert_manager_crypto(['settings' => $this->settings]);
		if (!$crypto->available()) {
			$this->warning('the key file cannot be read, so no certificate can be ordered');
			return;
		}
		$order = new certificate_order(['database' => $this->database, 'settings' => $this->settings, 'crypto' => $crypto]);
		foreach ($certificates as $certificate) {
			$status = $order->step($certificate['certificate_uuid']);
			$this->debug($certificate['certificate_name'] . ': ' . $status);
			if ($status == 'valid') {
				$this->info('the certificate ' . $certificate['certificate_name'] . ' was issued');
			}
			elseif ($status == 'failed') {
				$this->warning('the order of the certificate ' . $certificate['certificate_name'] . ' failed');
			}
		}
	}

	/**
	 * Deploys the certificates that changed since they were last deployed.
	 *
	 * @return void
	 */
	private function deploy_certificates(): void {
		$crypto = new cert_manager_crypto(['settings' => $this->settings]);
		if (!$crypto->available()) {
			return;
		}
		$deployer = new certificate_deployer(['database' => $this->database, 'settings' => $this->settings, 'crypto' => $crypto]);
		foreach ($deployer->deploy_pending() as $result) {
			if ($result['status'] == 'failed') {
				$this->warning('deploying ' . $result['name'] . ' to ' . $result['target'] . ' failed: ' . $result['message']);
			}
			elseif ($result['status'] == 'waiting') {
				$this->info('handed ' . $result['name'] . ' to the nginx helper');
			}
			else {
				$this->info('deployed ' . $result['name'] . ' to ' . $result['target']);
			}
		}
	}

	/**
	 * Removes the log entries older than the retention setting, once an hour.
	 *
	 * @return void
	 */
	private function trim_log(): void {
		if ($this->log_retention_days < 1 || time() - $this->last_trim < 3600) {
			return;
		}
		$this->last_trim = time();
		$sql = "delete from v_certificate_logs ";
		$sql .= "where log_date < now() - make_interval(days => :days) ";
		$parameters['days'] = $this->log_retention_days;
		$this->database->execute($sql, $parameters);
		unset($sql, $parameters);
	}

	/**
	 * Shows the version of this service.
	 *
	 * @return void
	 */
	protected static function display_version(): void {
		echo "1.0\n";
	}

	/**
	 * Adds the command line options of this service.
	 *
	 * @return void
	 */
	protected static function set_command_options() {

	}

}
