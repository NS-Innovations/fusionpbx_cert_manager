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
	 * when the expiry dates were last checked
	 * @var int
	 */
	private $last_warning = 0;

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
					$this->warn_expiring();
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
	 * A certificate is due when fewer than its renew days are left, but never earlier than half way through its
	 * life. Without that, renew days longer than the certificate lasts, such as 60 days for a 45 day
	 * certificate, would make every new certificate due the moment it is issued and renew it over and over.
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
		$sql .= "		(c.certificate_not_after is null or c.certificate_not_after < now() + least(make_interval(days => coalesce(c.certificate_renew_days, :renew_days)::int), (c.certificate_not_after - c.certificate_not_before) / 2)) ";
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
				$this->notify_failed($certificate['certificate_uuid']);
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
	 * Emails a warning for every certificate getting close to its expiry, once an hour.
	 *
	 * A warning goes out when the days left first drop to or below each of the warn_days, so with the default
	 * 30,14,7,1 a certificate that is not renewed warns four times. A certificate ordered over ACME only warns
	 * once it is past its renew days, which means its renewal is failing; an imported one cannot renew itself,
	 * so it warns from the first of the warn days.
	 *
	 * @return void
	 */
	private function warn_expiring(): void {
		if (time() - $this->last_warning < 3600) {
			return;
		}
		$this->last_warning = time();

		//the days to warn at, from the largest
		$warn_days = array_filter(array_map('intval', explode(',', (string) $this->settings->get('cert_manager', 'warn_days', '30,14,7,1'))), function ($days) { return $days > 0; });
		rsort($warn_days);
		if (empty($warn_days)) {
			return;
		}

		$sql = "select certificate_uuid, certificate_name, certificate_source, certificate_domains, certificate_renew_days, certificate_warned, ";
		$sql .= "to_char(certificate_not_after, 'YYYY-MM-DD HH24:MI') as expires, ";
		$sql .= "floor(extract(epoch from (certificate_not_after - now())) / 86400) as days_left, ";
		$sql .= "floor(extract(epoch from (certificate_not_after - certificate_not_before)) / 86400 / 2) as half_life ";
		$sql .= "from v_certificates ";
		$sql .= "where certificate_enabled = true ";
		$sql .= "and certificate_not_after is not null ";
		$sql .= "and certificate_not_after < now() + make_interval(days => :days) ";
		$parameters['days'] = $warn_days[0] + 1;
		$certificates = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);

		foreach ($certificates ?: [] as $certificate) {
			$days_left = (int) $certificate['days_left'];

			//an acme certificate is only a concern once its renewal is overdue
			$renew_days = min((int) ($certificate['certificate_renew_days'] ?: $this->renew_days), (int) $certificate['half_life']);
			if ($certificate['certificate_source'] == 'acme' && $days_left >= $renew_days) {
				continue;
			}

			//the smallest warn day the certificate has reached, warned about only once
			$reached = null;
			foreach ($warn_days as $days) {
				if ($days_left <= $days) {
					$reached = $days;
				}
			}
			if ($reached === null || ($certificate['certificate_warned'] !== null && $reached >= (int) $certificate['certificate_warned'])) {
				continue;
			}

			$values = [
				'${name}' => $certificate['certificate_name'],
				'${domains}' => implode(', ', certificates::split_domains($certificate['certificate_domains'])),
				'${expires}' => $certificate['expires'],
				'${days}' => (string) max($days_left, 0),
			];
			$type = $certificate['certificate_source'] == 'import' ? 'certificate_expiring_import' : 'certificate_expiring_acme';
			if ($this->send_email($type, $values)) {
				$array['certificates'][0]['certificate_uuid'] = $certificate['certificate_uuid'];
				$array['certificates'][0]['certificate_warned'] = $reached;
				$p = permissions::new();
				$p->add('certificate_edit', 'temp');
				$this->database->save($array, false);
				$p->delete('certificate_edit', 'temp');
				unset($array);
			}
		}
	}

	/**
	 * Emails that an order failed, the first time and then about once a day while it keeps failing.
	 *
	 * @param string $certificate_uuid The certificate.
	 *
	 * @return void
	 */
	private function notify_failed(string $certificate_uuid): void {
		$sql = "select certificate_name, certificate_domains, certificate_message, certificate_attempts, ";
		$sql .= "to_char(certificate_next_attempt, 'YYYY-MM-DD HH24:MI') as next_attempt, ";
		$sql .= "coalesce(to_char(certificate_not_after, 'YYYY-MM-DD HH24:MI'), '') as expires ";
		$sql .= "from v_certificates ";
		$sql .= "where certificate_uuid = :certificate_uuid ";
		$parameters['certificate_uuid'] = $certificate_uuid;
		$certificate = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (empty($certificate)) {
			return;
		}

		//the backoff reaches a day at the sixth attempt
		$attempts = (int) $certificate['certificate_attempts'];
		if ($attempts != 1 && $attempts < 6) {
			return;
		}
		$this->send_email('certificate_failed', [
			'${name}' => $certificate['certificate_name'],
			'${domains}' => implode(', ', certificates::split_domains($certificate['certificate_domains'])),
			'${message}' => (string) $certificate['certificate_message'],
			'${next_attempt}' => (string) $certificate['next_attempt'],
			'${expires}' => $certificate['expires'],
		]);
	}

	/**
	 * Emails the notify_email address, through the email of the platform.
	 *
	 * @param string $type   The kind of email; the language keys email_subject-<type> and email_body-<type> hold it.
	 * @param array  $values The values to put in it.
	 *
	 * @return bool Whether the email was sent or queued.
	 */
	private function send_email(string $type, array $values): bool {
		$recipient = (string) $this->settings->get('cert_manager', 'notify_email', '');
		if ($recipient === '') {
			return false;
		}
		$from_address = (string) $this->settings->get('email', 'smtp_from', '');
		if ($from_address === '') {
			$this->warning('the certificate manager cannot send email until smtp_from is set in the default settings');
			return false;
		}

		$language = new text;
		$text = $language->get(null, 'app/cert_manager');
		$email = new email(['database' => $this->database, 'settings' => $this->settings]);
		$email->recipients = $recipient;
		$email->subject = str_replace(array_keys($values), array_values($values), $text['email_subject-' . $type]);
		$email->body = str_replace(array_keys($values), array_values($values), $text['email_body-' . $type]);
		$email->from_address = $from_address;
		$email->from_name = $this->settings->get('email', 'smtp_from_name', $from_address);
		$response = $email->send();
		$sent = $response !== false && empty($email->error);
		$this->info('emailed ' . $recipient . ' about ' . $values['${name}'] . ': ' . ($sent ? 'sent' : (string) $email->error));
		return $sent;
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
