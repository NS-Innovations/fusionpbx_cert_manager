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
 * dns providers class
 *
 * Finds the DNS provider types that are installed and builds a provider from a saved record.
 */
class dns_providers {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * Set in the constructor. Must be a database object and cannot be null.
	 *
	 * @var database Database Object
	 */
	private $database;

	/**
	 * declare private variables
	 */
	private $permission_prefix;
	private $list_page;
	private $table;
	private $uuid_prefix;
	private $toggle_field;
	private $toggle_values;

	/**
	 * Initializes the object with the provided settings.
	 *
	 * @param array $setting_array An optional array of settings, defaulting to an empty array.
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		//set objects
		$this->database = $setting_array['database'] ?? database::new();

		//assign private variables
		$this->permission_prefix = 'dns_provider_';
		$this->list_page         = 'dns_providers.php';
		$this->table             = 'dns_providers';
		$this->uuid_prefix       = 'dns_provider_';
		$this->toggle_field      = 'dns_provider_enabled';
		$this->toggle_values     = ['true', 'false'];
	}

	/**
	 * The provider types that are installed: every dns_provider_<type>.php beside this file holding a class
	 * that extends dns_provider.
	 *
	 * @return array The class names keyed by type, in alphabetical order.
	 */
	public static function types(): array {
		$types = [];
		foreach (glob(__DIR__ . '/dns_provider_*.php') ?: [] as $file) {
			$class_name = basename($file, '.php');
			if (!class_exists($class_name, false)) {
				require_once $file;
			}
			if (class_exists($class_name, false) && is_subclass_of($class_name, 'dns_provider')) {
				$types[$class_name::type()] = $class_name;
			}
		}
		ksort($types);
		return $types;
	}

	/**
	 * Builds a provider of a type.
	 *
	 * @param string $type   The type.
	 * @param array  $values The values of its fields.
	 *
	 * @return dns_provider
	 * @throws Exception When the type is not installed.
	 */
	public static function create(string $type, array $values = []): dns_provider {
		$types = self::types();
		if (empty($types[$type])) {
			throw new Exception('the DNS provider type ' . $type . ' is not installed');
		}
		return new $types[$type]($values);
	}

	/**
	 * Builds the provider of a saved record.
	 *
	 * @param string              $dns_provider_uuid The record.
	 * @param cert_manager_crypto $crypto            Decrypts the settings.
	 *
	 * @return dns_provider
	 * @throws Exception When the record is missing or disabled, or its type is not installed.
	 */
	public function load(string $dns_provider_uuid, cert_manager_crypto $crypto): dns_provider {
		$sql = "select dns_provider_type, dns_provider_settings, cast(dns_provider_enabled as text) as dns_provider_enabled ";
		$sql .= "from v_dns_providers ";
		$sql .= "where dns_provider_uuid = :dns_provider_uuid ";
		$parameters['dns_provider_uuid'] = $dns_provider_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (!is_array($row) || @sizeof($row) == 0) {
			throw new Exception('the DNS provider does not exist');
		}
		if ($row['dns_provider_enabled'] != 'true') {
			throw new Exception('the DNS provider is disabled');
		}
		return self::create($row['dns_provider_type'], self::decode_settings($row['dns_provider_settings'], $crypto));
	}

	/**
	 * Decrypts and decodes the stored settings of a provider.
	 *
	 * @param string|null         $value  The stored value.
	 * @param cert_manager_crypto $crypto Decrypts the value.
	 *
	 * @return array The values keyed by field name.
	 * @throws Exception When the value cannot be decrypted.
	 */
	public static function decode_settings(?string $value, cert_manager_crypto $crypto): array {
		$json = $crypto->decrypt($value);
		$values = $json !== '' ? json_decode($json, true) : [];
		return is_array($values) ? $values : [];
	}

	/**
	 * Encodes and encrypts the settings of a provider for storing.
	 *
	 * @param array               $values The values keyed by field name.
	 * @param cert_manager_crypto $crypto Encrypts the value.
	 *
	 * @return string
	 * @throws Exception When the key file cannot be read.
	 */
	public static function encode_settings(array $values, cert_manager_crypto $crypto): string {
		return $crypto->encrypt(json_encode($values, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Deletes one or more DNS providers. A DNS provider still used by a certificate is kept.
	 *
	 * @param array $records An array of record IDs to delete, where each ID is an associative array
	 *                       containing 'uuid' and 'checked' keys.
	 *
	 * @return void No return value; this method modifies the database state and sets a message.
	 */
	public function delete($records) {
		if (permission_exists($this->permission_prefix . 'delete')) {

			//add multi-lingual support
			$language = new text;
			$text     = $language->get();

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: ' . $this->list_page);
				exit;
			}

			//delete multiple records
			if (is_array($records) && @sizeof($records) != 0) {

				//filter out the unchecked DNS providers
				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$uuids[] = $record['uuid'];
					}
				}

				//keep the DNS providers a certificate still uses
				if (is_array($uuids) && @sizeof($uuids) != 0) {
					$sql = "select distinct dns_provider_uuid from v_certificates ";
					$sql .= "where dns_provider_uuid in ('" . implode("', '", $uuids) . "') ";
					$rows = $this->database->select($sql, null, 'all');
					unset($sql);
					if (is_array($rows) && @sizeof($rows) != 0) {
						$in_use = array_column($rows, 'dns_provider_uuid');
						$uuids = array_values(array_diff($uuids, $in_use));
						message::add($text['message-dns_provider_in_use'], 'negative');
					}
					unset($rows, $in_use);
				}

				//build the delete array
				$array = [];
				$x = 0;
				foreach ($uuids as $uuid) {
					$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
					$x++;
				}

				//delete the checked rows
				if (!empty($array) && is_array($array) && @sizeof($array) != 0) {
					$this->database->delete($array);
					unset($array);

					//set message
					message::add($text['message-delete']);
				}
				unset($records);
			}
		}
	}

	/**
	 * Toggles the enabled state of one or more DNS providers.
	 *
	 * @param array $records An array of record IDs to toggle, where each ID is an associative array
	 *                       containing 'uuid' and 'checked' keys.
	 *
	 * @return void No return value; this method modifies the database state and sets a message.
	 */
	public function toggle($records) {
		if (permission_exists($this->permission_prefix . 'edit')) {

			//add multi-lingual support
			$language = new text;
			$text     = $language->get();

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: ' . $this->list_page);
				exit;
			}

			//toggle the checked records
			if (is_array($records) && @sizeof($records) != 0) {

				//get the current toggle state
				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$uuids[] = "'" . $record['uuid'] . "'";
					}
				}
				$states = [];
				if (is_array($uuids) && @sizeof($uuids) != 0) {
					$sql = "select " . $this->uuid_prefix . "uuid as uuid, " . $this->toggle_field . " as toggle from v_" . $this->table . " ";
					$sql .= "where " . $this->uuid_prefix . "uuid in (" . implode(', ', $uuids) . ") ";
					$rows = $this->database->select($sql, null, 'all');
					if (is_array($rows) && @sizeof($rows) != 0) {
						foreach ($rows as $row) {
							$states[$row['uuid']] = $row['toggle'];
						}
					}
					unset($sql, $rows, $row);
				}

				//build the update array
				$array = [];
				$x = 0;
				foreach ($states as $uuid => $state) {
					$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $uuid;
					$array[$this->table][$x][$this->toggle_field]         = $state == $this->toggle_values[0] ? $this->toggle_values[1] : $this->toggle_values[0];
					$x++;
				}

				//save the changes
				if (!empty($array) && is_array($array) && @sizeof($array) != 0) {
					$this->database->save($array);
					unset($array);

					//set message
					message::add($text['message-toggle']);
				}
				unset($records);
			}
		}
	}

}
