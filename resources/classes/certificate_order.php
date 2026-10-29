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
 * Orders a certificate over ACME, one step at a time.
 *
 * An order goes through these stages, and the state of the order is saved on the certificate after each
 * step, so the service can pick it up again after a restart:
 *
 * 1. start      a new key is created, the order is placed and every authorization gets its challenge
 *               published: a file for http-01, a TXT record for dns-01
 * 2. published  the service waits until every published record can be seen, then answers the challenges
 * 3. responded  the authority checks the challenges; once every authorization is valid the order is
 *               finalized with a certificate signing request
 * 4. finalized  the certificate is downloaded and saved, and the published challenges are removed
 *
 * A step never waits long. Whatever is not ready yet is looked at again on the next step, which keeps one
 * slow order, such as one waiting for a DNS record somebody adds by hand, from holding up the others.
 */
class certificate_order {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * how long a step waits for the authority before leaving the rest to the next step, in seconds
	 * @var int
	 */
	const poll_seconds = 20;

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
	 * decrypts and encrypts the stored secrets
	 * @var cert_manager_crypto
	 */
	private $crypto;

	/**
	 * the certificate being ordered, as read from the database
	 * @var array
	 */
	private $certificate;

	/**
	 * the state of the order in progress
	 * @var array
	 */
	private $state;

	/**
	 * the client of the account the certificate is ordered with
	 * @var acme_client
	 */
	private $client;

	/**
	 * the DNS provider of the certificate, built the first time it is needed
	 * @var dns_provider|null
	 */
	private $provider;

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
	 * Takes the order of a certificate one step further, starting it when none is in progress.
	 *
	 * @param string $certificate_uuid The certificate.
	 *
	 * @return string The status of the certificate after the step.
	 */
	public function step(string $certificate_uuid): string {
		//get the certificate and its account
		$sql = "select c.certificate_uuid, c.certificate_name, c.certificate_domains, c.certificate_challenge, ";
		$sql .= "c.dns_provider_uuid, c.certificate_key_type, c.certificate_order, c.certificate_attempts, ";
		$sql .= "a.acme_account_uuid, a.acme_account_directory, a.acme_account_key, a.acme_account_url, ";
		$sql .= "cast(a.acme_account_enabled as text) as acme_account_enabled ";
		$sql .= "from v_certificates as c ";
		$sql .= "left join v_acme_accounts as a on a.acme_account_uuid = c.acme_account_uuid ";
		$sql .= "where c.certificate_uuid = :certificate_uuid ";
		$parameters['certificate_uuid'] = $certificate_uuid;
		$this->certificate = $this->database->select($sql, $parameters, 'row') ?: [];
		unset($sql, $parameters);
		if (empty($this->certificate)) {
			return '';
		}
		$this->state = json_decode((string) $this->certificate['certificate_order'], true) ?: [];
		$this->provider = null;

		try {
			//the account has to be usable
			if (empty($this->certificate['acme_account_uuid'])) {
				throw new Exception('the certificate has no ACME account');
			}
			if ($this->certificate['acme_account_enabled'] != 'true') {
				throw new Exception('the ACME account is disabled');
			}
			$this->client = $this->client();

			//an order that has outlived itself cannot be completed
			if (!empty($this->state['expires']) && $this->state['expires'] < time()) {
				throw new Exception('the order expired before it was completed');
			}

			//take the step the order is at
			switch ($this->state['stage'] ?? '') {
				case '':
					$this->start();
					break;
				case 'published':
					$this->answer();
					break;
				case 'responded':
					$this->check();
					break;
				case 'finalized':
					$this->collect();
					break;
			}
			return $this->state['stage'] == 'done' ? 'valid' : $this->status();
		}
		catch (Exception $e) {
			$this->fail($e->getMessage());
			return 'failed';
		}
	}

	/**
	 * Places the order and publishes a challenge for every authorization that is not yet valid.
	 *
	 * @return void
	 * @throws Exception When the order cannot be placed or a challenge cannot be published.
	 */
	private function start(): void {
		$domains = certificates::split_domains($this->certificate['certificate_domains']);
		if (empty($domains)) {
			throw new Exception('the certificate has no names');
		}

		//every order gets a new key
		$private_key = acme_client::generate_key($this->certificate['certificate_key_type'] ?: 'ec-256');

		//place the order
		$order = $this->client->new_order($domains);
		$this->state = [
			'stage' => 'published',
			'url' => $order['url'],
			'finalize' => $order['finalize'],
			'expires' => !empty($order['expires']) ? strtotime($order['expires']) : time() + 86400,
			'started' => time(),
			'key' => $this->crypto->encrypt($private_key),
			'authorizations' => [],
		];
		$this->save_state('ordering');
		$this->log('info', 'order placed for ' . implode(', ', $domains));

		//publish a challenge for each authorization
		foreach ($order['authorizations'] as $authorization_url) {
			$authorization = $this->client->get($authorization_url);
			$domain = $authorization['identifier']['value'];
			$entry = [
				'url' => $authorization_url,
				'domain' => (!empty($authorization['wildcard']) ? '*.' : '') . $domain,
				'status' => $authorization['status'],
			];
			if ($authorization['status'] != 'valid') {
				$type = $this->certificate['certificate_challenge'] ?: 'http-01';
				$challenge = null;
				foreach ($authorization['challenges'] as $candidate) {
					if ($candidate['type'] == $type) {
						$challenge = $candidate;
					}
				}
				if ($challenge === null) {
					throw new Exception('the authority offers no ' . $type . ' challenge for ' . $entry['domain']);
				}
				$entry['type'] = $type;
				$entry['challenge'] = $challenge['url'];
				$entry['token'] = $challenge['token'];
				$this->publish($entry);
			}
			$this->state['authorizations'][] = $entry;
			$this->save_state('ordering');
		}
		$this->state['published'] = time();
		$this->save_state($this->status());
		$this->log('info', 'challenges published');
		if ($this->status() == 'waiting_dns') {
			foreach ($this->state['authorizations'] as $entry) {
				if (!empty($entry['fqdn'])) {
					$this->log('info', 'waiting for the TXT record ' . $entry['fqdn'] . ' with the value ' . $entry['value']);
				}
			}
		}
	}

	/**
	 * Publishes the challenge of one authorization.
	 *
	 * @param array $entry The authorization; gets the location of what was published added.
	 *
	 * @return void
	 * @throws Exception When the challenge cannot be published.
	 */
	private function publish(array &$entry): void {
		if ($entry['type'] == 'http-01') {
			if (!preg_match('/^[A-Za-z0-9_-]+$/', $entry['token'])) {
				throw new Exception('the authority sent a token that is not safe to use as a file name');
			}
			$directory = rtrim($this->settings->get('cert_manager', 'http_challenge_dir', '/var/www/dehydrated'), '/');
			if (!is_dir($directory) || !is_writable($directory)) {
				throw new Exception('the http-01 directory ' . $directory . ' does not exist or cannot be written');
			}
			$entry['file'] = $directory . '/' . $entry['token'];
			if (file_put_contents($entry['file'], $this->client->key_authorization($entry['token'])) === false) {
				throw new Exception('the http-01 challenge could not be written to ' . $entry['file']);
			}
			@chmod($entry['file'], 0644);
			return;
		}
		if ($entry['type'] == 'dns-01') {
			$entry['fqdn'] = '_acme-challenge.' . preg_replace('/^\*\./', '', $entry['domain']);
			$entry['value'] = $this->client->dns_value($entry['token']);
			$entry['manual'] = $this->provider()::manual();
			$entry['record'] = $this->provider()->create_txt($entry['fqdn'], $entry['value']);
			return;
		}
		throw new Exception('the challenge type ' . $entry['type'] . ' is not supported');
	}

	/**
	 * Answers the challenges once everything published can be seen.
	 *
	 * @return void
	 * @throws Exception When the authority refuses an answer.
	 */
	private function answer(): void {
		foreach ($this->state['authorizations'] as $entry) {
			if ($entry['status'] != 'valid' && !$this->visible($entry)) {
				//a record added through an API that does not show up in time is not going to
				$timeout = (int) $this->settings->get('cert_manager', 'propagation_timeout', 600);
				if (empty($entry['manual']) && time() - ($this->state['published'] ?? time()) > $timeout) {
					throw new Exception('the TXT record ' . $entry['fqdn'] . ' could not be seen after ' . $timeout . ' seconds');
				}
				return;
			}
		}
		foreach ($this->state['authorizations'] as $index => $entry) {
			if ($entry['status'] != 'valid') {
				$this->client->respond($entry['challenge']);
			}
		}
		$this->state['stage'] = 'responded';
		$this->save_state('ordering');
		$this->log('info', 'challenges answered');
		$this->check();
	}

	/**
	 * Whether what was published for an authorization can be seen by the authority.
	 *
	 * @param array $entry The authorization.
	 *
	 * @return bool
	 */
	private function visible(array $entry): bool {
		if (($entry['type'] ?? '') != 'dns-01') {
			return true;
		}

		//every resolver has to see the value, since the authority may ask any of them
		$resolvers = $this->settings->get('cert_manager', 'dns_resolver', ['https://cloudflare-dns.com/dns-query']);
		foreach ((array) $resolvers as $resolver) {
			$ch = curl_init($resolver . '?' . http_build_query(['name' => $entry['fqdn'], 'type' => 'TXT']));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dns-json']);
			$response = json_decode((string) curl_exec($ch), true);
			$found = false;
			foreach ($response['Answer'] ?? [] as $answer) {
				if (($answer['type'] ?? 0) == 16 && trim(str_replace('" "', '', $answer['data'] ?? ''), '"') === $entry['value']) {
					$found = true;
				}
			}
			if (!$found) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Waits a little for the authorizations to become valid, then finalizes the order.
	 *
	 * @return void
	 * @throws Exception When an authorization fails or the order cannot be finalized.
	 */
	private function check(): void {
		$deadline = time() + self::poll_seconds;
		do {
			$pending = false;
			foreach ($this->state['authorizations'] as $index => $entry) {
				if ($entry['status'] == 'valid') {
					continue;
				}
				$authorization = $this->client->get($entry['url']);
				$this->state['authorizations'][$index]['status'] = $authorization['status'];
				if ($authorization['status'] == 'invalid') {
					throw new Exception('validation of ' . $entry['domain'] . ' failed: ' . $this->challenge_error($authorization));
				}
				if ($authorization['status'] != 'valid') {
					$pending = true;
				}
			}
			if ($pending) {
				sleep(2);
			}
		} while ($pending && time() < $deadline);
		if ($pending) {
			$this->save_state('ordering');
			return;
		}

		//every name is validated, so the order can be finalized
		$this->log('info', 'every name is validated');
		$private_key = $this->crypto->decrypt($this->state['key']);
		$csr = acme_client::csr($private_key, certificates::split_domains($this->certificate['certificate_domains']));
		$this->client->finalize($this->state['finalize'], $csr);
		$this->state['stage'] = 'finalized';
		$this->save_state('ordering');
		$this->collect();
	}

	/**
	 * Waits a little for the certificate to be issued, then downloads and saves it.
	 *
	 * @return void
	 * @throws Exception When the order fails or the certificate does not match the key.
	 */
	private function collect(): void {
		$deadline = time() + self::poll_seconds;
		do {
			$order = $this->client->get($this->state['url']);
			if ($order['status'] == 'invalid') {
				throw new Exception('the authority did not issue the certificate');
			}
			if ($order['status'] != 'valid') {
				sleep(2);
			}
		} while ($order['status'] != 'valid' && time() < $deadline);
		if ($order['status'] != 'valid') {
			return;
		}

		//download and check the certificate
		$chain = cert_manager_pem::certificates($this->client->download($order['certificate']));
		$private_key = $this->crypto->decrypt($this->state['key']);
		if (empty($chain) || !cert_manager_pem::key_matches($private_key, $chain[0])) {
			throw new Exception('the certificate that was issued does not match the key of the order');
		}
		$details = cert_manager_pem::details($chain[0]);

		//remove what was published
		$this->clean_up();

		//save the certificate
		$array['certificates'][0]['certificate_uuid'] = $this->certificate['certificate_uuid'];
		$array['certificates'][0]['certificate_private_key'] = $this->state['key'];
		$array['certificates'][0]['certificate_pem'] = $chain[0];
		$array['certificates'][0]['certificate_chain'] = implode('', array_slice($chain, 1));
		$array['certificates'][0]['certificate_serial'] = $details['serial'];
		$array['certificates'][0]['certificate_issuer'] = $details['issuer'];
		$array['certificates'][0]['certificate_not_before'] = $details['not_before'];
		$array['certificates'][0]['certificate_not_after'] = $details['not_after'];
		$array['certificates'][0]['certificate_status'] = 'valid';
		$array['certificates'][0]['certificate_message'] = null;
		$array['certificates'][0]['certificate_order'] = null;
		$array['certificates'][0]['certificate_renew'] = 'false';
		$array['certificates'][0]['certificate_attempts'] = 0;
		$array['certificates'][0]['certificate_next_attempt'] = null;
		$array['certificates'][0]['certificate_warned'] = null;
		$this->save($array, 'certificate_edit');
		$this->state['stage'] = 'done';
		$this->log('info', 'certificate issued by ' . $details['issuer'] . ', serial ' . $details['serial'] . ', valid until ' . $details['not_after']);
	}

	/**
	 * Gives up on the order: removes what was published, records the reason and schedules the next attempt.
	 *
	 * Attempts back off from one hour, doubling each time up to a day, so a certificate that keeps failing does
	 * not run into the rate limits of the authority.
	 *
	 * @param string $message The reason.
	 *
	 * @return void
	 */
	private function fail(string $message): void {
		try {
			$this->clean_up();
		}
		catch (Exception $e) {
			$this->log('warning', 'clean up failed: ' . $e->getMessage());
		}
		$attempts = (int) ($this->certificate['certificate_attempts'] ?? 0) + 1;
		$delay = min(86400, 3600 * (2 ** min($attempts - 1, 5)));
		$array['certificates'][0]['certificate_uuid'] = $this->certificate['certificate_uuid'];
		$array['certificates'][0]['certificate_status'] = 'failed';
		$array['certificates'][0]['certificate_message'] = $message;
		$array['certificates'][0]['certificate_order'] = null;
		$array['certificates'][0]['certificate_renew'] = 'false';
		$array['certificates'][0]['certificate_attempts'] = $attempts;
		$array['certificates'][0]['certificate_next_attempt'] = gmdate('Y-m-d H:i:s', time() + $delay) . '+00';
		$this->save($array, 'certificate_edit');
		$this->log('error', $message);
	}

	/**
	 * Removes every challenge that was published for the order.
	 *
	 * @return void
	 * @throws Exception When something published cannot be removed.
	 */
	private function clean_up(): void {
		foreach ($this->state['authorizations'] ?? [] as $entry) {
			if (!empty($entry['file']) && file_exists($entry['file'])) {
				unlink($entry['file']);
			}
			if (!empty($entry['fqdn']) && empty($entry['manual'])) {
				$this->provider()->delete_txt($entry['fqdn'], (string) ($entry['record'] ?? ''));
			}
		}
	}

	/**
	 * The status the certificate shows while the order is in progress.
	 *
	 * @return string
	 */
	private function status(): string {
		if (($this->state['stage'] ?? '') == 'published') {
			foreach ($this->state['authorizations'] ?? [] as $entry) {
				if (!empty($entry['manual']) && $entry['status'] != 'valid') {
					return 'waiting_dns';
				}
			}
		}
		return 'ordering';
	}

	/**
	 * The DNS provider of the certificate.
	 *
	 * @return dns_provider
	 * @throws Exception When the certificate has no usable provider.
	 */
	private function provider(): dns_provider {
		if ($this->provider === null) {
			if (empty($this->certificate['dns_provider_uuid'])) {
				throw new Exception('the certificate has no DNS provider');
			}
			$providers = new dns_providers(['database' => $this->database]);
			$this->provider = $providers->load($this->certificate['dns_provider_uuid'], $this->crypto);
		}
		return $this->provider;
	}

	/**
	 * Finds the error the authority gave for the challenge of a failed authorization.
	 *
	 * @param array $authorization The authorization.
	 *
	 * @return string
	 */
	private function challenge_error(array $authorization): string {
		foreach ($authorization['challenges'] ?? [] as $challenge) {
			if (!empty($challenge['error']['detail'])) {
				return $challenge['error']['detail'];
			}
		}
		return 'no reason given';
	}

	/**
	 * Builds the client of the account, registering the account first when it is not registered yet.
	 *
	 * @return acme_client
	 * @throws Exception When the account cannot be registered.
	 */
	private function client(): acme_client {
		if (empty($this->certificate['acme_account_url']) || empty($this->certificate['acme_account_key'])) {
			$accounts = new acme_accounts(['database' => $this->database]);
			$accounts->register($this->certificate['acme_account_uuid'], $this->crypto);
			$sql = "select acme_account_key, acme_account_url from v_acme_accounts where acme_account_uuid = :acme_account_uuid ";
			$parameters['acme_account_uuid'] = $this->certificate['acme_account_uuid'];
			$row = $this->database->select($sql, $parameters, 'row');
			unset($sql, $parameters);
			$this->certificate['acme_account_key'] = $row['acme_account_key'];
			$this->certificate['acme_account_url'] = $row['acme_account_url'];
		}
		return new acme_client($this->certificate['acme_account_directory'], $this->crypto->decrypt($this->certificate['acme_account_key']), $this->certificate['acme_account_url']);
	}

	/**
	 * Saves the state of the order on the certificate.
	 *
	 * @param string $status The status the certificate shows.
	 *
	 * @return void
	 */
	private function save_state(string $status): void {
		$array['certificates'][0]['certificate_uuid'] = $this->certificate['certificate_uuid'];
		$array['certificates'][0]['certificate_order'] = json_encode($this->state, JSON_UNESCAPED_SLASHES);
		$array['certificates'][0]['certificate_status'] = $status;
		$array['certificates'][0]['certificate_message'] = null;
		$this->save($array, 'certificate_edit');
	}

	/**
	 * Writes an entry to the log of the certificate.
	 *
	 * @param string $level   info, warning or error.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function log(string $level, string $message): void {
		$array['certificate_logs'][0]['certificate_log_uuid'] = uuid();
		$array['certificate_logs'][0]['certificate_uuid'] = $this->certificate['certificate_uuid'];
		$array['certificate_logs'][0]['log_date'] = 'now()';
		$array['certificate_logs'][0]['log_level'] = $level;
		$array['certificate_logs'][0]['log_message'] = $message;
		$this->save($array, 'certificate_log_add');
	}

	/**
	 * Saves with a temporary permission, since the service has no user.
	 *
	 * @param array  $array      The records.
	 * @param string $permission The permission the save layer needs.
	 *
	 * @return void
	 */
	private function save(array $array, string $permission): void {
		$p = permissions::new();
		$p->add($permission, 'temp');
		$this->database->save($array, false);
		$p->delete($permission, 'temp');
	}

}
