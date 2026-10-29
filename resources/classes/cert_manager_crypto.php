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
 * Encrypts the secrets the app stores in the database.
 *
 * The private keys of the certificates, the keys of the ACME accounts and the credentials of the DNS
 * providers are sealed with libsodium secretbox, using a 32 byte key read from a file outside the
 * database. A copy of the database alone, such as a backup, does not give the secrets away. Anything
 * that can read the key file, which includes the web server, can still decrypt them.
 *
 * An encrypted value is written as "v1:" followed by the base64 of the nonce and the ciphertext, so
 * the format can change later without breaking the values already stored.
 */
class cert_manager_crypto {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * the prefix of an encrypted value
	 * @var string
	 */
	const prefix = 'v1:';

	/**
	 * the path of the key file
	 * @var string
	 */
	private $key_file;

	/**
	 * the key, read from the key file the first time it is needed
	 * @var string|null
	 */
	private $key;

	/**
	 * Initializes the object with the provided settings.
	 *
	 * @param array $setting_array An optional array with 'settings' (a settings object) or 'key_file'.
	 *
	 * @return void
	 */
	public function __construct(array $setting_array = []) {
		//use the key file given, or the one in the default settings
		if (!empty($setting_array['key_file'])) {
			$this->key_file = $setting_array['key_file'];
		}
		else {
			$settings = $setting_array['settings'] ?? new settings(['database' => $setting_array['database'] ?? database::new()]);
			$this->key_file = $settings->get('cert_manager', 'key_file', '/etc/fusionpbx/cert_manager.key');
		}
	}

	/**
	 * Whether the key file exists and holds a usable key.
	 *
	 * @return bool
	 */
	public function available(): bool {
		try {
			$this->key();
			return true;
		}
		catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Encrypts a value. An empty value stays empty so a blank field does not look like a secret.
	 *
	 * @param string $plain The value to encrypt.
	 *
	 * @return string The encrypted value.
	 * @throws Exception When the key file cannot be read.
	 */
	public function encrypt(string $plain): string {
		if ($plain === '') {
			return '';
		}
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = sodium_crypto_secretbox($plain, $nonce, $this->key());
		return self::prefix . base64_encode($nonce . $cipher);
	}

	/**
	 * Decrypts a value written by encrypt().
	 *
	 * @param string $value The encrypted value.
	 *
	 * @return string The plain value.
	 * @throws Exception When the key file cannot be read, or the value was not encrypted with this key.
	 */
	public function decrypt(?string $value): string {
		if ($value === null || $value === '') {
			return '';
		}
		if (substr($value, 0, strlen(self::prefix)) !== self::prefix) {
			throw new Exception('the value is not encrypted in a known format');
		}
		$raw = base64_decode(substr($value, strlen(self::prefix)), true);
		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new Exception('the encrypted value is damaged');
		}
		$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
		if ($plain === false) {
			throw new Exception('the value could not be decrypted with the key in ' . $this->key_file);
		}
		return $plain;
	}

	/**
	 * Creates a new random key, in the format the key file holds.
	 *
	 * @return string The base64 of 32 random bytes.
	 */
	public static function new_key(): string {
		return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
	}

	/**
	 * Reads the key from the key file.
	 *
	 * @return string The raw key.
	 * @throws Exception When the file is missing, unreadable or does not hold a key.
	 */
	private function key(): string {
		if ($this->key !== null) {
			return $this->key;
		}
		if (empty($this->key_file) || !is_readable($this->key_file)) {
			throw new Exception('the key file ' . $this->key_file . ' cannot be read');
		}
		$key = base64_decode(trim((string) file_get_contents($this->key_file)), true);
		if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
			throw new Exception('the key file ' . $this->key_file . ' does not hold a valid key');
		}
		$this->key = $key;
		return $this->key;
	}

}
