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
 * A DNS provider for DNS hosted somewhere without an API.
 *
 * Nothing is sent anywhere. The certificate page shows the TXT records the order needs, somebody adds
 * them at their DNS host, and the service carries on with the order once every resolver sees them. The
 * records can be removed by hand after the certificate is issued.
 *
 * It is also the smallest example of a provider.
 */
class dns_provider_manual extends dns_provider {

	/**
	 * A person adds the records, so the service waits for them for as long as the order is valid.
	 *
	 * @return bool
	 */
	public static function manual(): bool {
		return true;
	}

	/**
	 * Nothing to add; the record is shown on the certificate page instead.
	 *
	 * @param string $fqdn  The full name of the record.
	 * @param string $value The value of the record.
	 *
	 * @return string An empty id.
	 */
	public function create_txt(string $fqdn, string $value): string {
		return '';
	}

	/**
	 * Nothing to remove.
	 *
	 * @param string $fqdn      The full name of the record.
	 * @param string $record_id The id create_txt() returned.
	 *
	 * @return void
	 */
	public function delete_txt(string $fqdn, string $record_id): void {
	}

}
