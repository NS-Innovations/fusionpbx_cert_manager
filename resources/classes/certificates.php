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
 * certificates class
 */
class certificates {

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
		$this->permission_prefix = 'certificate_';
		$this->list_page         = 'certificates.php';
		$this->table             = 'certificates';
		$this->uuid_prefix       = 'certificate_';
		$this->toggle_field      = 'certificate_enabled';
		$this->toggle_values     = ['true', 'false'];
	}

	/**
	 * Checks a list of names a certificate is ordered for.
	 *
	 * @param array $domains The names, lowercase. A name may start with "*." for a wildcard.
	 *
	 * @return array The names that are not valid.
	 */
	public static function invalid_domains(array $domains): array {
		$invalid = [];
		foreach ($domains as $domain) {
			$name = substr($domain, 0, 2) == '*.' ? substr($domain, 2) : $domain;
			if (strlen($name) > 253 || !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $name)) {
				$invalid[] = $domain;
			}
		}
		return $invalid;
	}

	/**
	 * Splits the stored names of a certificate into a list.
	 *
	 * @param string|null $domains The names separated by new lines, commas or spaces.
	 *
	 * @return array The names, lowercase and without duplicates, in their order.
	 */
	public static function split_domains(?string $domains): array {
		$list = preg_split('/[\s,]+/', strtolower((string) $domains), -1, PREG_SPLIT_NO_EMPTY);
		return array_values(array_unique($list));
	}

	/**
	 * Asks the service to renew one or more certificates now, without waiting for them to be due.
	 *
	 * @param array $records An array of record IDs, where each ID is an associative array containing 'uuid'
	 *                       and 'checked' keys.
	 *
	 * @return void No return value; this method modifies the database state and sets a message.
	 */
	public function renew($records) {
		if (permission_exists('certificate_renew')) {

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

			//flag the checked certificates that are ordered over acme
			if (is_array($records) && @sizeof($records) != 0) {
				$uuids = [];
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$uuids[] = "'" . $record['uuid'] . "'";
					}
				}
				$array = [];
				if (is_array($uuids) && @sizeof($uuids) != 0) {
					$sql = "select certificate_uuid from v_certificates ";
					$sql .= "where certificate_uuid in (" . implode(', ', $uuids) . ") ";
					$sql .= "and certificate_source = 'acme' ";
					$rows = $this->database->select($sql, null, 'all');
					unset($sql);
					$x = 0;
					foreach ($rows ?: [] as $row) {
						$array[$this->table][$x]['certificate_uuid'] = $row['certificate_uuid'];
						$array[$this->table][$x]['certificate_renew'] = 'true';
						$array[$this->table][$x]['certificate_attempts'] = 0;
						$array[$this->table][$x]['certificate_next_attempt'] = null;
						$x++;
					}
					unset($rows, $row);
				}

				//save the changes
				if (!empty($array) && is_array($array) && @sizeof($array) != 0) {
					$this->database->save($array);
					unset($array);

					//set message
					message::add($text['message-certificate_renew_requested']);
				}
				unset($records);
			}
		}
	}

	/**
	 * Deletes deployments of a certificate. The files already deployed are left where they are.
	 *
	 * @param string $certificate_uuid The certificate the deployments belong to.
	 * @param array  $records          An array of records, where each is an associative array containing
	 *                                 'uuid' and 'checked' keys.
	 *
	 * @return void No return value; this method modifies the database state.
	 */
	public function delete_deployments(string $certificate_uuid, $records) {
		if (permission_exists('certificate_deployment_delete') && is_uuid($certificate_uuid)) {

			//build the delete array
			$array = [];
			$x = 0;
			foreach ($records ?: [] as $record) {
				if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'] ?? '')) {
					$array['certificate_deployments'][$x]['certificate_deployment_uuid'] = $record['uuid'];
					$array['certificate_deployments'][$x]['certificate_uuid'] = $certificate_uuid;
					$x++;
				}
			}

			//delete the checked rows
			if (!empty($array) && is_array($array) && @sizeof($array) != 0) {
				$this->database->delete($array);
				unset($array);
			}
		}
	}

	/**
	 * Deletes one or more certificates along with their deployments and their log.
	 *
	 * The files already deployed are left where they are, so deleting a certificate never takes a running
	 * service down.
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

				//build the delete array
				$array = [];
				$x = 0;
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$array['certificate_logs'][$x]['certificate_uuid'] = $record['uuid'];
						$array['certificate_deployments'][$x]['certificate_uuid'] = $record['uuid'];
						$array[$this->table][$x][$this->uuid_prefix . 'uuid'] = $record['uuid'];
						$x++;
					}
				}

				//delete the checked rows
				if (!empty($array) && is_array($array) && @sizeof($array) != 0) {

					//grant temporary permissions
					$p = permissions::new();
					$p->add('certificate_log_delete', 'temp');
					$p->add('certificate_deployment_delete', 'temp');

					//execute delete
					$this->database->delete($array);
					unset($array);

					//revoke temporary permissions
					$p->delete('certificate_log_delete', 'temp');
					$p->delete('certificate_deployment_delete', 'temp');

					//set message
					message::add($text['message-delete']);
				}
				unset($records);
			}
		}
	}

	/**
	 * Toggles the enabled state of one or more certificates.
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
