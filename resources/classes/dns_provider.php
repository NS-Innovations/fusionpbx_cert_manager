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
 * The base of a DNS provider, which adds and removes the TXT records that prove control of a name for
 * the dns-01 challenge.
 *
 * To add a provider, create resources/classes/dns_provider_<type>.php holding a class of that name that
 * extends this one, and add its language keys to app_languages.php:
 *
 * - option-dns_provider_<type>               the name of the provider
 * - label-dns_provider_<type>_<field>        the label of each field
 * - description-dns_provider_<type>_<field>  the help text of each field
 *
 * The provider then appears on the DNS provider page with its fields, and nothing else has to change.
 * Checking that a record has propagated is done by the service for every provider, so a provider only
 * has to talk to its own API.
 */
abstract class dns_provider {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * the values of the fields, keyed by field name
	 * @var array
	 */
	protected $values;

	/**
	 * Initializes the provider with the values of its fields.
	 *
	 * @param array $values The values keyed by field name.
	 */
	public function __construct(array $values = []) {
		$this->values = $values;
	}

	/**
	 * The type of the provider, taken from its class name.
	 *
	 * @return string
	 */
	public static function type(): string {
		return substr(static::class, strlen('dns_provider_'));
	}

	/**
	 * The fields the provider needs, which the edit page renders.
	 *
	 * Each field is an array with:
	 * - name:     the field name, used in the language keys
	 * - type:     text, password or textarea
	 * - required: whether the field must be filled in
	 * - secret:   whether the saved value is never shown again; a blank entry keeps it
	 *
	 * @return array
	 */
	public static function fields(): array {
		return [];
	}

	/**
	 * Whether a person adds the records instead of an API. The service then waits for the record to appear
	 * for as long as the order is valid, rather than for the propagation timeout.
	 *
	 * @return bool
	 */
	public static function manual(): bool {
		return false;
	}

	/**
	 * Checks that the settings work, for the Test button of the edit page.
	 *
	 * @return string A message describing what was found, such as the zones the credentials reach.
	 * @throws Exception When the settings do not work.
	 */
	public function test(): string {
		return '';
	}

	/**
	 * Adds a TXT record.
	 *
	 * @param string $fqdn  The full name of the record, such as _acme-challenge.example.com, without a trailing dot.
	 * @param string $value The value of the record.
	 *
	 * @return string An id the provider needs to remove the record again.
	 * @throws Exception When the record cannot be added.
	 */
	abstract public function create_txt(string $fqdn, string $value): string;

	/**
	 * Removes a TXT record added by create_txt().
	 *
	 * @param string $fqdn      The full name of the record.
	 * @param string $record_id The id create_txt() returned.
	 *
	 * @return void
	 * @throws Exception When the record cannot be removed.
	 */
	abstract public function delete_txt(string $fqdn, string $record_id): void;

	/**
	 * The value of a field.
	 *
	 * @param string $name The field name.
	 *
	 * @return string
	 */
	protected function value(string $name): string {
		return trim((string) ($this->values[$name] ?? ''));
	}

	/**
	 * Sends an HTTPS request to the API of the provider.
	 *
	 * @param string     $method  The HTTP method.
	 * @param string     $url     The URL.
	 * @param array      $headers The request headers, as "Name: value" strings.
	 * @param array|null $json    A body to send as JSON.
	 *
	 * @return array ['status' => int, 'body' => string, 'json' => array|null]
	 * @throws Exception When the API cannot be reached.
	 */
	protected function http(string $method, string $url, array $headers = [], ?array $json = null): array {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_USERAGENT, 'FusionPBX-cert_manager/1.0');
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES));
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$body = curl_exec($ch);
		if ($body === false) {
			throw new Exception('the API of the DNS provider could not be reached: ' . curl_error($ch));
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$decoded = json_decode((string) $body, true);
		return ['status' => $status, 'body' => (string) $body, 'json' => is_array($decoded) ? $decoded : null];
	}

}
