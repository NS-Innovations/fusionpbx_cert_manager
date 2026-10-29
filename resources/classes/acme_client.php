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
 * A client for the ACME protocol (RFC 8555), the protocol Let's Encrypt and other certificate authorities
 * use to issue certificates.
 *
 * It needs nothing beyond the openssl and curl extensions of PHP. Requests are signed JSON web signatures:
 * ES256 or ES384 for an EC account key, RS256 for an RSA one. New accounts are signed with the public key
 * itself, everything after that with the account URL.
 *
 * Errors are thrown as an Exception whose message carries the problem the certificate authority sent.
 */
class acme_client {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * the key types a certificate or an account key can have
	 * @var array
	 */
	const key_types = ['ec-256', 'ec-384', 'rsa-2048', 'rsa-4096'];

	/**
	 * the URL of the ACME directory
	 * @var string
	 */
	private $directory_url;

	/**
	 * the directory, fetched the first time it is needed
	 * @var array|null
	 */
	private $directory;

	/**
	 * the private key of the account
	 * @var OpenSSLAsymmetricKey|null
	 */
	private $account_key;

	/**
	 * the URL of the account, empty until the account is registered
	 * @var string
	 */
	private $account_url;

	/**
	 * the nonce the last response handed out, used by the next request
	 * @var string
	 */
	private $nonce = '';

	/**
	 * Initializes the client.
	 *
	 * @param string $directory_url   The URL of the ACME directory.
	 * @param string $account_key_pem The private key of the account, in PEM format. Without one the client can
	 *                                only read the directory.
	 * @param string $account_url     The URL of the account when it is already registered.
	 *
	 * @throws Exception When the key cannot be read.
	 */
	public function __construct(string $directory_url, string $account_key_pem = '', string $account_url = '') {
		$this->directory_url = $directory_url;
		$this->account_url = $account_url;
		if ($account_key_pem !== '') {
			$key = openssl_pkey_get_private($account_key_pem);
			if ($key === false) {
				throw new Exception('the account key cannot be read');
			}
			$this->account_key = $key;
		}
	}

	/**
	 * Creates a new private key.
	 *
	 * @param string $key_type One of key_types.
	 *
	 * @return string The private key in PEM format.
	 * @throws Exception When the type is unknown or openssl fails.
	 */
	public static function generate_key(string $key_type = 'ec-256'): string {
		switch ($key_type) {
			case 'ec-256':
				$options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
				break;
			case 'ec-384':
				$options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'];
				break;
			case 'rsa-2048':
				$options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
				break;
			case 'rsa-4096':
				$options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096];
				break;
			default:
				throw new Exception('unknown key type ' . $key_type);
		}
		$key = openssl_pkey_new($options);
		if ($key === false || !openssl_pkey_export($key, $pem)) {
			throw new Exception('the key could not be created: ' . openssl_error_string());
		}
		return $pem;
	}

	/**
	 * Fetches the directory of the certificate authority.
	 *
	 * @return array The directory, with the URLs of newNonce, newAccount, newOrder and the meta data.
	 * @throws Exception When the directory cannot be fetched.
	 */
	public function directory(): array {
		if ($this->directory === null) {
			$response = $this->http('GET', $this->directory_url);
			if ($response['status'] != 200 || !is_array($response['json']) || empty($response['json']['newAccount'])) {
				throw new Exception('the ACME directory ' . $this->directory_url . ' could not be read (HTTP ' . $response['status'] . ')');
			}
			$this->directory = $response['json'];
		}
		return $this->directory;
	}

	/**
	 * The terms of service of the certificate authority, when it has any.
	 *
	 * @return string The URL of the terms, or an empty string.
	 */
	public function terms_of_service(): string {
		return (string) ($this->directory()['meta']['termsOfService'] ?? '');
	}

	/**
	 * Whether the certificate authority requires an external account binding.
	 *
	 * @return bool
	 */
	public function requires_eab(): bool {
		return !empty($this->directory()['meta']['externalAccountRequired']);
	}

	/**
	 * Registers the account, or finds it when the key is already registered.
	 *
	 * @param string $email    The contact email, or an empty string for none.
	 * @param string $eab_kid  The key id of the external account binding, or an empty string for none.
	 * @param string $eab_hmac The HMAC key of the external account binding, base64url encoded as the authority gives it.
	 *
	 * @return array ['url' => the account URL, 'status' => the account status]
	 * @throws Exception When the authority refuses the account.
	 */
	public function register(string $email = '', string $eab_kid = '', string $eab_hmac = ''): array {
		$url = $this->directory()['newAccount'];
		$payload = ['termsOfServiceAgreed' => true];
		if ($email !== '') {
			$payload['contact'] = ['mailto:' . $email];
		}
		if ($eab_kid !== '' && $eab_hmac !== '') {
			$payload['externalAccountBinding'] = $this->external_account_binding($url, $eab_kid, $eab_hmac);
		}
		$response = $this->post($url, $payload, true);
		if (!in_array($response['status'], [200, 201]) || empty($response['headers']['location'])) {
			throw new Exception($this->problem($response, 'the account could not be registered'));
		}
		$this->account_url = $response['headers']['location'];
		return ['url' => $this->account_url, 'status' => $response['json']['status'] ?? 'valid'];
	}

	/**
	 * Replaces the contact email of the registered account.
	 *
	 * @param string $email The contact email, or an empty string for none.
	 *
	 * @return string The status of the account.
	 * @throws Exception When the authority refuses the change.
	 */
	public function update_contact(string $email): string {
		$response = $this->post($this->account_url, ['contact' => $email !== '' ? ['mailto:' . $email] : []]);
		if ($response['status'] != 200) {
			throw new Exception($this->problem($response, 'the contact of the account could not be changed'));
		}
		return $response['json']['status'] ?? 'valid';
	}

	/**
	 * Creates an order for a list of names.
	 *
	 * @param array $domains The names; a wildcard keeps its "*." prefix.
	 *
	 * @return array The order, with its URL added under 'url'.
	 * @throws Exception When the authority refuses the order.
	 */
	public function new_order(array $domains): array {
		$identifiers = [];
		foreach ($domains as $domain) {
			$identifiers[] = ['type' => 'dns', 'value' => $domain];
		}
		$response = $this->post($this->directory()['newOrder'], ['identifiers' => $identifiers]);
		if ($response['status'] != 201 || !is_array($response['json']) || empty($response['headers']['location'])) {
			throw new Exception($this->problem($response, 'the order could not be created'));
		}
		return array_merge($response['json'], ['url' => $response['headers']['location']]);
	}

	/**
	 * Fetches an order, an authorization or a challenge.
	 *
	 * @param string $url The URL of the object.
	 *
	 * @return array The object.
	 * @throws Exception When the object cannot be fetched.
	 */
	public function get(string $url): array {
		$response = $this->post($url, null);
		if ($response['status'] != 200 || !is_array($response['json'])) {
			throw new Exception($this->problem($response, 'the ACME object could not be fetched'));
		}
		return $response['json'];
	}

	/**
	 * Tells the authority a challenge is ready to be checked.
	 *
	 * @param string $url The URL of the challenge.
	 *
	 * @return array The challenge.
	 * @throws Exception When the authority refuses.
	 */
	public function respond(string $url): array {
		$response = $this->post($url, []);
		if ($response['status'] != 200 || !is_array($response['json'])) {
			throw new Exception($this->problem($response, 'the challenge could not be answered'));
		}
		return $response['json'];
	}

	/**
	 * Finalizes an order with the certificate signing request.
	 *
	 * @param string $url The finalize URL of the order.
	 * @param string $csr The certificate signing request in PEM format.
	 *
	 * @return array The order.
	 * @throws Exception When the authority refuses the request.
	 */
	public function finalize(string $url, string $csr): array {
		$der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $csr));
		$response = $this->post($url, ['csr' => self::base64url($der)]);
		if ($response['status'] != 200 || !is_array($response['json'])) {
			throw new Exception($this->problem($response, 'the order could not be finalized'));
		}
		return $response['json'];
	}

	/**
	 * Downloads the certificate of a valid order.
	 *
	 * @param string $url The certificate URL of the order.
	 *
	 * @return string The certificate followed by its chain, in PEM format.
	 * @throws Exception When the certificate cannot be downloaded.
	 */
	public function download(string $url): string {
		$response = $this->post($url, null, false, 'application/pem-certificate-chain');
		if ($response['status'] != 200 || strpos($response['body'], '-----BEGIN CERTIFICATE-----') === false) {
			throw new Exception($this->problem($response, 'the certificate could not be downloaded'));
		}
		return $response['body'];
	}

	/**
	 * Creates the certificate signing request of an order.
	 *
	 * Every name goes in the subject alternative names. The first name that fits is also used as the common
	 * name, which some older clients still read.
	 *
	 * @param string $private_key The private key of the certificate, in PEM format.
	 * @param array  $domains     The names.
	 *
	 * @return string The request in PEM format.
	 * @throws Exception When openssl cannot create it.
	 */
	public static function csr(string $private_key, array $domains): string {
		$key = openssl_pkey_get_private($private_key);
		if ($key === false) {
			throw new Exception('the certificate key cannot be read');
		}

		//openssl only takes the subject alternative names from a config file
		$names = [];
		foreach (array_values($domains) as $i => $domain) {
			$names[] = 'DNS.' . ($i + 1) . ' = ' . $domain;
		}
		$config = "[ req ]\ndistinguished_name = req_dn\nreq_extensions = req_ext\n[ req_dn ]\n[ req_ext ]\nsubjectAltName = @san\n[ san ]\n" . implode("\n", $names) . "\n";
		$config_file = tempnam(sys_get_temp_dir(), 'cert_manager_csr_');
		file_put_contents($config_file, $config);

		$subject = [];
		foreach ($domains as $domain) {
			if (strlen($domain) <= 64) {
				$subject['commonName'] = $domain;
				break;
			}
		}
		$csr = openssl_csr_new($subject, $key, ['config' => $config_file, 'digest_alg' => 'sha256', 'req_extensions' => 'req_ext']);
		unlink($config_file);
		if ($csr === false || !openssl_csr_export($csr, $pem)) {
			throw new Exception('the certificate signing request could not be created: ' . openssl_error_string());
		}
		return $pem;
	}

	/**
	 * The key authorization of a challenge: its token and the account thumbprint.
	 *
	 * @param string $token The token of the challenge.
	 *
	 * @return string
	 */
	public function key_authorization(string $token): string {
		return $token . '.' . $this->thumbprint();
	}

	/**
	 * The thumbprint of the account key (RFC 7638), which is part of every key authorization.
	 *
	 * @return string The base64url encoded SHA-256 thumbprint.
	 */
	public function thumbprint(): string {
		return self::base64url(hash('sha256', json_encode($this->jwk(), JSON_UNESCAPED_SLASHES), true));
	}

	/**
	 * Signs a request and posts it.
	 *
	 * The nonce is refreshed and the request sent again once when the authority answers badNonce, which it
	 * may do at any time.
	 *
	 * @param string     $url     The URL to post to.
	 * @param array|null $payload The payload, or null for a POST-as-GET.
	 * @param bool       $use_jwk Whether to sign with the public key instead of the account URL.
	 * @param string     $accept  The Accept header to send.
	 *
	 * @return array The response, see http().
	 * @throws Exception When the request cannot be sent.
	 */
	protected function post(string $url, ?array $payload, bool $use_jwk = false, string $accept = 'application/json'): array {
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$body = json_encode($this->jws($url, $payload, $use_jwk), JSON_UNESCAPED_SLASHES);
			$response = $this->http('POST', $url, $body, $accept);
			if ($response['status'] == 400 && ($response['json']['type'] ?? '') == 'urn:ietf:params:acme:error:badNonce') {
				continue;
			}
			return $response;
		}
		return $response;
	}

	/**
	 * Builds the problem message of a failed response.
	 *
	 * @param array  $response The response.
	 * @param string $default  The message when the authority gave none.
	 *
	 * @return string
	 */
	protected function problem(array $response, string $default): string {
		$json = $response['json'];
		if (is_array($json) && !empty($json['detail'])) {
			$type = str_replace('urn:ietf:params:acme:error:', '', (string) ($json['type'] ?? ''));
			$message = $json['detail'] . ($type !== '' ? ' (' . $type . ')' : '');
			foreach ($json['subproblems'] ?? [] as $subproblem) {
				$message .= '; ' . ($subproblem['identifier']['value'] ?? '') . ': ' . ($subproblem['detail'] ?? '');
			}
			return $message;
		}
		return $default . ' (HTTP ' . $response['status'] . ')';
	}

	/**
	 * Builds a JSON web signature.
	 *
	 * @param string     $url     The URL the request goes to.
	 * @param array|null $payload The payload, or null for an empty payload.
	 * @param bool       $use_jwk Whether to put the public key in the header instead of the account URL.
	 *
	 * @return array The flattened JWS.
	 * @throws Exception When there is no nonce or no account URL, or signing fails.
	 */
	private function jws(string $url, ?array $payload, bool $use_jwk): array {
		if ($this->account_key === null) {
			throw new Exception('the client has no account key');
		}
		$protected = ['alg' => $this->alg(), 'nonce' => $this->nonce(), 'url' => $url];
		if ($use_jwk) {
			$protected['jwk'] = $this->jwk();
		}
		else {
			if ($this->account_url === '') {
				throw new Exception('the account is not registered');
			}
			$protected['kid'] = $this->account_url;
		}
		$protected64 = self::base64url(json_encode($protected, JSON_UNESCAPED_SLASHES));
		$payload64 = $payload === null ? '' : self::base64url(json_encode((object) $payload, JSON_UNESCAPED_SLASHES));
		return [
			'protected' => $protected64,
			'payload' => $payload64,
			'signature' => self::base64url($this->sign($protected64 . '.' . $payload64)),
		];
	}

	/**
	 * Builds the external account binding, a JWS over the public key signed with the HMAC key.
	 *
	 * @param string $url      The newAccount URL.
	 * @param string $eab_kid  The key id.
	 * @param string $eab_hmac The base64url encoded HMAC key.
	 *
	 * @return array The flattened JWS.
	 */
	private function external_account_binding(string $url, string $eab_kid, string $eab_hmac): array {
		$protected64 = self::base64url(json_encode(['alg' => 'HS256', 'kid' => $eab_kid, 'url' => $url], JSON_UNESCAPED_SLASHES));
		$payload64 = self::base64url(json_encode($this->jwk(), JSON_UNESCAPED_SLASHES));
		$key = base64_decode(strtr($eab_hmac, '-_', '+/'));
		return [
			'protected' => $protected64,
			'payload' => $payload64,
			'signature' => self::base64url(hash_hmac('sha256', $protected64 . '.' . $payload64, $key, true)),
		];
	}

	/**
	 * Takes the nonce for the next request, fetching a fresh one when the last response left none.
	 *
	 * @return string
	 * @throws Exception When no nonce can be had.
	 */
	private function nonce(): string {
		if ($this->nonce === '') {
			$this->http('HEAD', $this->directory()['newNonce']);
		}
		if ($this->nonce === '') {
			throw new Exception('the certificate authority gave no nonce');
		}
		$nonce = $this->nonce;
		$this->nonce = '';
		return $nonce;
	}

	/**
	 * The signing algorithm the account key calls for.
	 *
	 * @return string
	 */
	private function alg(): string {
		$details = openssl_pkey_get_details($this->account_key);
		if ($details['type'] == OPENSSL_KEYTYPE_EC) {
			return $details['bits'] == 384 ? 'ES384' : 'ES256';
		}
		return 'RS256';
	}

	/**
	 * The public account key as a JSON web key, with the members in the order the thumbprint requires.
	 *
	 * @return array
	 */
	private function jwk(): array {
		$details = openssl_pkey_get_details($this->account_key);
		if ($details['type'] == OPENSSL_KEYTYPE_EC) {
			$size = $details['bits'] == 384 ? 48 : 32;
			return [
				'crv' => $details['bits'] == 384 ? 'P-384' : 'P-256',
				'kty' => 'EC',
				'x' => self::base64url(str_pad($details['ec']['x'], $size, "\0", STR_PAD_LEFT)),
				'y' => self::base64url(str_pad($details['ec']['y'], $size, "\0", STR_PAD_LEFT)),
			];
		}
		return [
			'e' => self::base64url($details['rsa']['e']),
			'kty' => 'RSA',
			'n' => self::base64url($details['rsa']['n']),
		];
	}

	/**
	 * Signs data with the account key. An EC signature is converted from DER to the raw r and s the JWS needs.
	 *
	 * @param string $data The data to sign.
	 *
	 * @return string The signature.
	 * @throws Exception When signing fails.
	 */
	private function sign(string $data): string {
		$alg = $this->alg();
		$digest = $alg == 'ES384' ? OPENSSL_ALGO_SHA384 : OPENSSL_ALGO_SHA256;
		if (!openssl_sign($data, $signature, $this->account_key, $digest)) {
			throw new Exception('the request could not be signed: ' . openssl_error_string());
		}
		if ($alg == 'RS256') {
			return $signature;
		}
		return self::der_to_raw($signature, $alg == 'ES384' ? 48 : 32);
	}

	/**
	 * Converts a DER encoded ECDSA signature into the fixed length r || s form.
	 *
	 * @param string $der  The DER signature: SEQUENCE { INTEGER r, INTEGER s }.
	 * @param int    $size The length of r and s in bytes.
	 *
	 * @return string
	 */
	private static function der_to_raw(string $der, int $size): string {
		$offset = 2;
		if (ord($der[1]) & 0x80) {
			$offset += ord($der[1]) & 0x7f;
		}
		$raw = '';
		for ($i = 0; $i < 2; $i++) {
			$length = ord($der[$offset + 1]);
			$integer = substr($der, $offset + 2, $length);
			$offset += 2 + $length;
			$integer = ltrim($integer, "\0");
			$raw .= str_pad($integer, $size, "\0", STR_PAD_LEFT);
		}
		return $raw;
	}

	/**
	 * Base64url encodes data without padding.
	 *
	 * @param string $data
	 *
	 * @return string
	 */
	public static function base64url(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Sends a request and keeps the nonce the response hands out.
	 *
	 * @param string $method The HTTP method.
	 * @param string $url    The URL.
	 * @param string $body   The body to post.
	 * @param string $accept The Accept header.
	 *
	 * @return array ['status' => int, 'headers' => array with lowercase names, 'body' => string, 'json' => array|null]
	 * @throws Exception When the authority cannot be reached.
	 */
	protected function http(string $method, string $url, string $body = '', string $accept = 'application/json'): array {
		if (!preg_match('#^https://#i', $url)) {
			throw new Exception('refusing to send an ACME request to a URL that is not https: ' . $url);
		}
		$headers = [];
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_USERAGENT, 'FusionPBX-cert_manager/1.0');
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/jose+json', 'Accept: ' . $accept]);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
			$parts = explode(':', $line, 2);
			if (count($parts) == 2) {
				$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
			return strlen($line);
		});
		if ($method == 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		elseif ($method == 'HEAD') {
			curl_setopt($ch, CURLOPT_NOBODY, true);
		}
		$response_body = curl_exec($ch);
		if ($response_body === false) {
			$error = curl_error($ch);
			throw new Exception('the certificate authority could not be reached: ' . $error);
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (!empty($headers['replay-nonce'])) {
			$this->nonce = $headers['replay-nonce'];
		}
		$json = json_decode((string) $response_body, true);
		return ['status' => $status, 'headers' => $headers, 'body' => (string) $response_body, 'json' => is_array($json) ? $json : null];
	}

}
