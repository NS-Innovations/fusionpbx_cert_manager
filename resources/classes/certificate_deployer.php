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
 * Deploys certificates to where they are used.
 *
 * A deployment links a certificate to a target. Whenever the serial number of the certificate differs from
 * the one last deployed, which happens when it is renewed or imported again, the certificate is written to
 * the target again. Files are written to a temporary name and renamed into place, so a reader never sees half
 * a file.
 */
class certificate_deployer {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * the targets a certificate can be deployed to
	 * @var array
	 */
	const targets = ['nginx', 'freeswitch'];

	/**
	 * how long to wait for the root helper to reload nginx before giving up, in seconds
	 * @var int
	 */
	const nginx_timeout = 300;

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
	 * decrypts the stored private keys
	 * @var cert_manager_crypto
	 */
	private $crypto;

	/**
	 * Initializes the object with the provided settings.
	 *
	 * @param array $setting_array 'database', 'settings' and 'crypto'.
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? database::new();
		$this->settings = $setting_array['settings'] ?? new settings(['database' => $this->database]);
		$this->crypto = $setting_array['crypto'] ?? new cert_manager_crypto(['settings' => $this->settings]);
	}

	/**
	 * Deploys every enabled deployment whose certificate changed since it was last deployed.
	 *
	 * @return array The results, one per deployment: name, target, status and message.
	 */
	public function deploy_pending(): array {
		$results = $this->check_nginx();

		$sql = "select d.certificate_deployment_uuid, d.deployment_target, d.deployment_options, ";
		$sql .= "c.certificate_uuid, c.certificate_name, c.certificate_serial, c.certificate_pem, c.certificate_chain, c.certificate_private_key ";
		$sql .= "from v_certificate_deployments as d ";
		$sql .= "join v_certificates as c on c.certificate_uuid = d.certificate_uuid ";
		$sql .= "where d.deployment_enabled = true ";
		$sql .= "and c.certificate_enabled = true ";
		$sql .= "and c.certificate_serial is not null ";
		$sql .= "and c.certificate_not_after > now() ";
		$sql .= "and d.deployment_serial is distinct from c.certificate_serial ";
		$sql .= "and (d.deployment_status is distinct from 'failed' or d.update_date < now() - interval '1 hour' or d.update_date is null) ";
		$rows = $this->database->select($sql, null, 'all');
		unset($sql);

		foreach ($rows ?: [] as $row) {
			$results[] = $this->deploy($row);
		}
		return $results;
	}

	/**
	 * Deploys one certificate to one target and records the result.
	 *
	 * @param array $row The deployment joined with its certificate.
	 *
	 * @return array name, target, status and message.
	 */
	public function deploy(array $row): array {
		$array = [];
		try {
			$certificate = cert_manager_pem::certificates($row['certificate_pem'])[0] ?? '';
			$chain = implode('', cert_manager_pem::certificates($row['certificate_chain']));
			$private_key = cert_manager_pem::private_key($this->crypto->decrypt($row['certificate_private_key']));
			if ($certificate === '' || $private_key === '') {
				throw new Exception('the certificate or its key is missing');
			}
			switch ($row['deployment_target']) {
				case 'nginx':
					[$status, $message] = $this->deploy_nginx($certificate, $chain, $private_key, $row['certificate_serial']);
					break;
				case 'freeswitch':
					[$status, $message] = $this->deploy_freeswitch($certificate, $chain, $private_key);
					break;
				default:
					throw new Exception('unknown target ' . $row['deployment_target']);
			}
			$array['certificate_deployments'][0]['deployment_serial'] = $row['certificate_serial'];
			$array['certificate_deployments'][0]['deployment_date'] = 'now()';
		}
		catch (Exception $e) {
			$status = 'failed';
			$message = $e->getMessage();
		}

		//record the result
		$this->record($row, $status, $message, $array);
		return ['name' => $row['certificate_name'], 'target' => $row['deployment_target'], 'status' => $status, 'message' => $message];
	}

	/**
	 * Records the result of a deployment on the deployment and in the log of the certificate.
	 *
	 * @param array  $row     The deployment joined with its certificate.
	 * @param string $status  The status of the deployment.
	 * @param string $message The message.
	 * @param array  $array   Anything else to save with it.
	 *
	 * @return void
	 */
	private function record(array $row, string $status, string $message, array $array = []): void {
		$array['certificate_deployments'][0]['certificate_deployment_uuid'] = $row['certificate_deployment_uuid'];
		$array['certificate_deployments'][0]['deployment_status'] = $status;
		$array['certificate_deployments'][0]['deployment_message'] = $message;
		if ($status != 'waiting') {
			$array['certificate_logs'][0]['certificate_log_uuid'] = uuid();
			$array['certificate_logs'][0]['certificate_uuid'] = $row['certificate_uuid'];
			$array['certificate_logs'][0]['log_date'] = 'now()';
			$array['certificate_logs'][0]['log_level'] = $status == 'failed' ? 'error' : 'info';
			$array['certificate_logs'][0]['log_message'] = 'deploy to ' . $row['deployment_target'] . ': ' . $message;
		}
		$p = permissions::new();
		$p->add('certificate_deployment_edit', 'temp');
		$p->add('certificate_log_add', 'temp');
		$this->database->save($array, false);
		$p->delete('certificate_deployment_edit', 'temp');
		$p->delete('certificate_log_add', 'temp');
	}

	/**
	 * Writes the certificate where the root helper picks it up, and asks the helper to give it to nginx.
	 *
	 * The service runs as www-data and can neither write where nginx reads its certificate nor reload nginx.
	 * Writing the request file wakes cert_manager_nginx.path, whose root helper checks the files, copies them
	 * where nginx reads them and reloads nginx, putting everything back when nginx does not accept them. The
	 * result is read back by check_nginx().
	 *
	 * @param string $certificate The certificate.
	 * @param string $chain       Its intermediates.
	 * @param string $private_key Its key.
	 * @param string $serial      Its serial number.
	 *
	 * @return array The status and the message.
	 * @throws Exception When a file cannot be written.
	 */
	private function deploy_nginx(string $certificate, string $chain, string $private_key, string $serial): array {
		$store = rtrim($this->settings->get('cert_manager', 'store_dir', '/etc/fusionpbx/cert_manager'), '/');
		foreach ([$store . '/live/nginx', $store . '/deploy'] as $directory) {
			if (!is_dir($directory) || !is_writable($directory)) {
				throw new Exception('the directory ' . $directory . ' does not exist or cannot be written; run the install script of the app');
			}
		}
		self::write_file($store . '/live/nginx/fullchain.pem', $certificate . $chain, 0640);
		self::write_file($store . '/live/nginx/privkey.pem', $private_key, 0600);
		self::write_file($store . '/deploy/nginx.request', $serial . "\n", 0644);
		return ['waiting', 'waiting for nginx to reload'];
	}

	/**
	 * Reads the result of the root helper for every nginx deployment waiting for it.
	 *
	 * @return array The results that came in, as deploy() returns them.
	 */
	private function check_nginx(): array {
		$sql = "select d.certificate_deployment_uuid, d.deployment_target, d.deployment_serial, d.deployment_date, ";
		$sql .= "extract(epoch from (now() - d.deployment_date)) as waited, c.certificate_uuid, c.certificate_name ";
		$sql .= "from v_certificate_deployments as d ";
		$sql .= "join v_certificates as c on c.certificate_uuid = d.certificate_uuid ";
		$sql .= "where d.deployment_target = 'nginx' ";
		$sql .= "and d.deployment_status = 'waiting' ";
		$rows = $this->database->select($sql, null, 'all');
		unset($sql);

		$results = [];
		$store = rtrim($this->settings->get('cert_manager', 'store_dir', '/etc/fusionpbx/cert_manager'), '/');
		foreach ($rows ?: [] as $row) {
			$result = explode(' ', trim((string) @file_get_contents($store . '/deploy/nginx.result')), 3);
			if (count($result) == 3 && $result[1] === $row['deployment_serial']) {
				$status = $result[0] == 'ok' ? 'deployed' : 'failed';
				$message = $result[2];
			}
			elseif ($row['waited'] > self::nginx_timeout) {
				$status = 'failed';
				$message = 'nginx was not reloaded within ' . self::nginx_timeout . ' seconds; check that cert_manager_nginx.path is enabled';
			}
			else {
				continue;
			}

			//a failed deployment forgets its serial so it is tried again
			$array = [];
			if ($status == 'failed') {
				$array['certificate_deployments'][0]['deployment_serial'] = null;
			}
			$this->record($row, $status, $message, $array);
			$results[] = ['name' => $row['certificate_name'], 'target' => 'nginx', 'status' => $status, 'message' => $message];
		}
		return $results;
	}

	/**
	 * Writes the certificate to the TLS directory of FreeSWITCH, in the layout the FusionPBX installer uses.
	 *
	 * The files in the directory before the first deployment are kept in cert_manager.bak beside them.
	 * Sofia only reads the files when a profile starts, so the new certificate takes effect when the profiles
	 * using TLS are restarted, which drops their calls; that is left to a person.
	 *
	 * @param string $certificate The certificate.
	 * @param string $chain       Its intermediates.
	 * @param string $private_key Its key.
	 *
	 * @return array The status and the message.
	 * @throws Exception When a file cannot be written.
	 */
	private function deploy_freeswitch(string $certificate, string $chain, string $private_key): array {
		$directory = rtrim($this->settings->get('cert_manager', 'freeswitch_tls_dir', '/etc/freeswitch/tls'), '/');
		if (!is_dir($directory) || !is_writable($directory)) {
			throw new Exception('the directory ' . $directory . ' does not exist or cannot be written');
		}

		//keep what was there before the first deployment
		$backup = $directory . '/cert_manager.bak';
		if (!is_dir($backup)) {
			mkdir($backup, 0750);
			foreach (glob($directory . '/*.pem') ?: [] as $file) {
				copy($file, $backup . '/' . basename($file));
				chmod($backup . '/' . basename($file), 0640);
			}
		}

		//the same files the installer writes
		$fullchain = $certificate . $chain;
		$all = $fullchain . $private_key;
		$files = [
			'cert.pem' => $certificate,
			'chain.pem' => $chain,
			'fullchain.pem' => $fullchain,
			'privkey.pem' => $private_key,
			'all.pem' => $all,
			'agent.pem' => $all,
			'tls.pem' => $all,
			'wss.pem' => $all,
			'dtls-srtp.pem' => $all,
		];
		foreach ($files as $name => $content) {
			self::write_file($directory . '/' . $name, $content, 0640);
		}
		return ['restart', 'written to ' . $directory . '; takes effect when the SIP profiles using TLS restart'];
	}

	/**
	 * Writes a file atomically, replacing a symbolic link at the name rather than following it.
	 *
	 * @param string $file    The file.
	 * @param string $content The content.
	 * @param int    $mode    The permissions.
	 *
	 * @return void
	 * @throws Exception When the file cannot be written.
	 */
	public static function write_file(string $file, string $content, int $mode): void {
		$temporary = $file . '.tmp.' . getmypid();
		$old_umask = umask(0077);
		$written = file_put_contents($temporary, $content);
		umask($old_umask);
		if ($written === false || !chmod($temporary, $mode) || !rename($temporary, $file)) {
			@unlink($temporary);
			throw new Exception('the file ' . $file . ' could not be written');
		}
	}

}
