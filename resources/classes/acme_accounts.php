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
 * acme accounts class
 */
class acme_accounts {

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
		$this->permission_prefix = 'acme_account_';
		$this->list_page         = 'acme_accounts.php';
		$this->table             = 'acme_accounts';
		$this->uuid_prefix       = 'acme_account_';
		$this->toggle_field      = 'acme_account_enabled';
		$this->toggle_values     = ['true', 'false'];
	}

	/**
	 * The certificate authorities offered on the edit page. Any other ACME directory can be entered by hand.
	 *
	 * @return array The directory URLs keyed by the language key of their name.
	 */
	public static function presets(): array {
		return [
			'option-letsencrypt' => 'https://acme-v02.api.letsencrypt.org/directory',
			'option-letsencrypt_staging' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
			'option-zerossl' => 'https://acme.zerossl.com/v2/DV90',
			'option-google' => 'https://dv.acme-v02.api.pki.goog/directory',
		];
	}

	/**
	 * Registers an account with its certificate authority, or brings the contact of a registered one up to date.
	 *
	 * The account key is created the first time. Registering a key that is already registered finds the
	 * existing account, so calling this again is harmless.
	 *
	 * @param string             $acme_account_uuid The account.
	 * @param cert_manager_crypto $crypto            Decrypts and encrypts the stored secrets.
	 *
	 * @return string The status of the account at the certificate authority.
	 * @throws Exception When the account is missing or the certificate authority refuses it.
	 */
	public function register(string $acme_account_uuid, cert_manager_crypto $crypto): string {
		//get the account
		$sql = "select acme_account_directory, acme_account_email, acme_account_eab_kid, acme_account_eab_hmac, ";
		$sql .= "acme_account_key, acme_account_url ";
		$sql .= "from v_acme_accounts ";
		$sql .= "where acme_account_uuid = :acme_account_uuid ";
		$parameters['acme_account_uuid'] = $acme_account_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (!is_array($row) || @sizeof($row) == 0) {
			throw new Exception('the account does not exist');
		}

		//create the account key the first time
		$array = [];
		$account_key = $crypto->decrypt($row['acme_account_key']);
		if ($account_key === '') {
			$account_key = acme_client::generate_key('ec-256');
			$array['acme_accounts'][0]['acme_account_key'] = $crypto->encrypt($account_key);
		}

		//register the account, or update its contact
		$client = new acme_client($row['acme_account_directory'], $account_key, (string) $row['acme_account_url']);
		if (empty($row['acme_account_url'])) {
			$result = $client->register((string) $row['acme_account_email'], (string) $row['acme_account_eab_kid'], $crypto->decrypt($row['acme_account_eab_hmac']));
			$array['acme_accounts'][0]['acme_account_url'] = $result['url'];
			$status = $result['status'];
		}
		else {
			$status = $client->update_contact((string) $row['acme_account_email']);
		}

		//save the result
		$array['acme_accounts'][0]['acme_account_uuid'] = $acme_account_uuid;
		$array['acme_accounts'][0]['acme_account_status'] = $status;
		$p = permissions::new();
		$p->add('acme_account_edit', 'temp');
		$this->database->save($array);
		$p->delete('acme_account_edit', 'temp');
		unset($array);

		return $status;
	}

	/**
	 * Deletes one or more accounts. An account still used by a certificate is kept.
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

				//filter out the unchecked accounts
				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$uuids[] = $record['uuid'];
					}
				}

				//keep the accounts a certificate still uses
				if (is_array($uuids) && @sizeof($uuids) != 0) {
					$sql = "select distinct acme_account_uuid from v_certificates ";
					$sql .= "where acme_account_uuid in ('" . implode("', '", $uuids) . "') ";
					$rows = $this->database->select($sql, null, 'all');
					unset($sql);
					if (is_array($rows) && @sizeof($rows) != 0) {
						$in_use = array_column($rows, 'acme_account_uuid');
						$uuids = array_values(array_diff($uuids, $in_use));
						message::add($text['message-acme_account_in_use'], 'negative');
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
	 * Toggles the enabled state of one or more accounts.
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
