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
 * Reads certificates and keys in PEM format.
 */
class cert_manager_pem {

	/**
	 * declare constant variables
	 */
	const app_name = 'cert_manager';
	const app_uuid = 'ece22aae-0e63-465c-ac60-57cd701edbc4';

	/**
	 * Splits text into the certificates it holds, ignoring anything around them.
	 *
	 * @param string|null $pem Text holding one or more certificates.
	 *
	 * @return array The certificates in PEM format, in their order.
	 */
	public static function certificates(?string $pem): array {
		preg_match_all('/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+?-----END CERTIFICATE-----/', (string) $pem, $matches);
		$certificates = [];
		foreach ($matches[0] as $block) {
			$certificates[] = self::normalize($block, 'CERTIFICATE');
		}
		return $certificates;
	}

	/**
	 * Finds the private key in text, in any of the PEM forms openssl writes.
	 *
	 * @param string|null $pem Text holding a private key.
	 *
	 * @return string The key in PEM format, or an empty string when there is none.
	 */
	public static function private_key(?string $pem): string {
		if (preg_match('/-----BEGIN ((?:RSA |EC )?PRIVATE KEY)-----\s+[A-Za-z0-9+\/=\s]+?-----END \1-----/', (string) $pem, $match)) {
			return self::normalize($match[0], $match[1]);
		}
		return '';
	}

	/**
	 * Reads the details of a certificate.
	 *
	 * @param string $certificate A certificate in PEM format.
	 *
	 * @return array serial (hex), issuer, subject, domains (the DNS names, or the common name when it has none),
	 *               not_before and not_after (UTC, as 'Y-m-d H:i:s+00')
	 * @throws Exception When the certificate cannot be read.
	 */
	public static function details(string $certificate): array {
		$x509 = openssl_x509_parse($certificate);
		if ($x509 === false) {
			throw new Exception('the certificate cannot be read');
		}
		$domains = [];
		foreach (explode(',', (string) ($x509['extensions']['subjectAltName'] ?? '')) as $name) {
			$name = trim($name);
			if (stripos($name, 'DNS:') === 0) {
				$domains[] = strtolower(substr($name, 4));
			}
		}
		if (empty($domains) && !empty($x509['subject']['CN'])) {
			$domains[] = strtolower(is_array($x509['subject']['CN']) ? $x509['subject']['CN'][0] : $x509['subject']['CN']);
		}
		return [
			'serial' => strtolower((string) ($x509['serialNumberHex'] ?? '')),
			'issuer' => self::name($x509['issuer'] ?? []),
			'subject' => self::name($x509['subject'] ?? []),
			'domains' => array_values(array_unique($domains)),
			'not_before' => gmdate('Y-m-d H:i:s', $x509['validFrom_time_t']) . '+00',
			'not_after' => gmdate('Y-m-d H:i:s', $x509['validTo_time_t']) . '+00',
		];
	}

	/**
	 * Whether a private key belongs to a certificate.
	 *
	 * @param string $private_key The key in PEM format.
	 * @param string $certificate The certificate in PEM format.
	 *
	 * @return bool
	 */
	public static function key_matches(string $private_key, string $certificate): bool {
		return $private_key !== '' && $certificate !== '' && @openssl_x509_check_private_key($certificate, $private_key);
	}

	/**
	 * The type of a private key, in the form of acme_client::key_types.
	 *
	 * @param string $private_key The key in PEM format.
	 *
	 * @return string The type, or an empty string when the key cannot be read.
	 */
	public static function key_type(string $private_key): string {
		$key = @openssl_pkey_get_private($private_key);
		if ($key === false) {
			return '';
		}
		$details = openssl_pkey_get_details($key);
		if ($details['type'] == OPENSSL_KEYTYPE_EC) {
			return 'ec-' . $details['bits'];
		}
		if ($details['type'] == OPENSSL_KEYTYPE_RSA) {
			return 'rsa-' . $details['bits'];
		}
		return '';
	}

	/**
	 * Rewraps a PEM block in 64 character lines, the form every reader accepts.
	 *
	 * @param string $block The block.
	 * @param string $label The label between BEGIN and END.
	 *
	 * @return string
	 */
	private static function normalize(string $block, string $label): string {
		$body = preg_replace('/-----(BEGIN|END) ' . preg_quote($label, '/') . '-----|\s+/', '', $block);
		return "-----BEGIN " . $label . "-----\n" . chunk_split($body, 64, "\n") . "-----END " . $label . "-----\n";
	}

	/**
	 * Formats a distinguished name for display, using its organization and common name.
	 *
	 * @param array $name The name as openssl_x509_parse returns it.
	 *
	 * @return string
	 */
	private static function name(array $name): string {
		$parts = [];
		foreach (['O', 'CN'] as $field) {
			if (!empty($name[$field])) {
				$parts[] = is_array($name[$field]) ? implode(', ', $name[$field]) : $name[$field];
			}
		}
		return implode(' - ', array_unique($parts));
	}

}
