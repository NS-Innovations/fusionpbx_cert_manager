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
 * Publishes the dns-01 records through the Cloudflare API.
 *
 * Needs an API token with the Zone > DNS > Edit permission for the zones of the certificates, and the
 * Zone > Zone > Read permission so the zone of each name can be found. A zone id can be given instead to
 * skip the lookup.
 */
class dns_provider_cloudflare extends dns_provider {

	/**
	 * the address of the API
	 * @var string
	 */
	const api = 'https://api.cloudflare.com/client/v4';

	/**
	 * The fields the provider needs.
	 *
	 * @return array
	 */
	public static function fields(): array {
		return [
			['name' => 'api_token', 'type' => 'password', 'required' => true, 'secret' => true],
			['name' => 'zone_id', 'type' => 'text', 'required' => false, 'secret' => false],
		];
	}

	/**
	 * Lists the zones the token reaches.
	 *
	 * @return string The names of the zones.
	 * @throws Exception When the token is refused or reaches no zone.
	 */
	public function test(): string {
		if ($this->value('zone_id') !== '') {
			$response = $this->request('GET', '/zones/' . rawurlencode($this->value('zone_id')));
			return $response['result']['name'] ?? '';
		}
		$response = $this->request('GET', '/zones?per_page=50');
		$names = array_column($response['result'] ?? [], 'name');
		if (empty($names)) {
			throw new Exception('the token is valid but reaches no zone');
		}
		return implode(', ', $names);
	}

	/**
	 * Adds a TXT record. A record that already exists with the same value is reused.
	 *
	 * @param string $fqdn  The full name of the record.
	 * @param string $value The value of the record.
	 *
	 * @return string The zone id and the record id, separated by a colon.
	 * @throws Exception When the zone cannot be found or the record cannot be added.
	 */
	public function create_txt(string $fqdn, string $value): string {
		$zone_id = $this->zone_id($fqdn);
		try {
			$response = $this->request('POST', '/zones/' . rawurlencode($zone_id) . '/dns_records', [
				'type' => 'TXT',
				'name' => $fqdn,
				'content' => $value,
				'ttl' => 120,
				'comment' => 'ACME challenge added by the FusionPBX certificate manager',
			]);
			$record_id = $response['result']['id'] ?? '';
		}
		catch (Exception $e) {
			//an identical record is left over from an earlier attempt
			if ($e->getCode() != 81058) {
				throw $e;
			}
			$query = http_build_query(['type' => 'TXT', 'name' => $fqdn, 'content' => $value]);
			$response = $this->request('GET', '/zones/' . rawurlencode($zone_id) . '/dns_records?' . $query);
			$record_id = $response['result'][0]['id'] ?? '';
		}
		if ($record_id === '') {
			throw new Exception('Cloudflare did not return the id of the record');
		}
		return $zone_id . ':' . $record_id;
	}

	/**
	 * Removes a TXT record added by create_txt(). A record that is already gone counts as removed.
	 *
	 * @param string $fqdn      The full name of the record.
	 * @param string $record_id The id create_txt() returned.
	 *
	 * @return void
	 * @throws Exception When the record cannot be removed.
	 */
	public function delete_txt(string $fqdn, string $record_id): void {
		[$zone_id, $id] = array_pad(explode(':', $record_id, 2), 2, '');
		if ($zone_id === '' || $id === '') {
			return;
		}
		try {
			$this->request('DELETE', '/zones/' . rawurlencode($zone_id) . '/dns_records/' . rawurlencode($id));
		}
		catch (Exception $e) {
			//record not found
			if ($e->getCode() != 81044) {
				throw $e;
			}
		}
	}

	/**
	 * Finds the zone a name belongs to, trying the name and then each parent until one is found.
	 *
	 * @param string $fqdn The full name of the record.
	 *
	 * @return string The zone id.
	 * @throws Exception When no zone the token reaches holds the name.
	 */
	private function zone_id(string $fqdn): string {
		if ($this->value('zone_id') !== '') {
			return $this->value('zone_id');
		}
		$labels = explode('.', strtolower($fqdn));
		while (count($labels) >= 2) {
			$name = implode('.', $labels);
			$response = $this->request('GET', '/zones?' . http_build_query(['name' => $name]));
			if (!empty($response['result'][0]['id'])) {
				return $response['result'][0]['id'];
			}
			array_shift($labels);
		}
		throw new Exception('no Cloudflare zone the token reaches holds ' . $fqdn);
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method The HTTP method.
	 * @param string     $path   The path below the API address.
	 * @param array|null $json   The body.
	 *
	 * @return array The decoded response.
	 * @throws Exception When the API reports an error; the code of the exception is the Cloudflare error code.
	 */
	private function request(string $method, string $path, ?array $json = null): array {
		if ($this->value('api_token') === '') {
			throw new Exception('the Cloudflare API token is missing');
		}

		//the global api key needs the account email and is not accepted as a token
		if (preg_match('/^[0-9a-f]{37}$/i', $this->value('api_token'))) {
			throw new Exception('Cloudflare: this is the Global API Key, not an API token; create a token under My Profile > API Tokens with Zone > DNS > Edit and Zone > Zone > Read');
		}

		$response = $this->http($method, self::api . $path, ['Authorization: Bearer ' . $this->value('api_token')], $json);
		if (!is_array($response['json']) || empty($response['json']['success'])) {
			$error = $response['json']['errors'][0] ?? [];
			$message = $error['message'] ?? ('HTTP ' . $response['status']);

			//add the specific reasons, such as an api token in the wrong format behind invalid request headers
			foreach ($error['error_chain'] ?? [] as $reason) {
				if (!empty($reason['message'])) {
					$message .= ': ' . $reason['message'];
				}
			}

			throw new Exception('Cloudflare: ' . $message, (int) ($error['code'] ?? 0));
		}
		return $response['json'];
	}

}
