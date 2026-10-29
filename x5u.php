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

/*
	Serves a published certificate with its intermediates, without a login.

	A STIR/SHAKEN signature names the URL of its certificate in x5u, and every carrier that verifies a call
	fetches the certificate from there. Only a certificate that is enabled and has Publish set is served;
	anything else, including an id that does not exist, is a plain 404. Only public certificates are ever
	read here, never a key.
*/

//includes files
	$no_session = true;
	require_once dirname(__DIR__, 2) . "/resources/require.php";

//get the certificate
	$certificate_uuid = $_GET['id'] ?? '';
	$row = [];
	if (is_uuid($certificate_uuid)) {
		$sql = "select certificate_pem, certificate_chain from v_certificates ";
		$sql .= "where certificate_uuid = :certificate_uuid ";
		$sql .= "and certificate_publish = true ";
		$sql .= "and certificate_enabled = true ";
		$sql .= "and certificate_pem is not null ";
		$parameters['certificate_uuid'] = $certificate_uuid;
		$row = $database->select($sql, $parameters, 'row') ?: [];
		unset($sql, $parameters);
	}

//send the certificate followed by its intermediates
	$certificates = cert_manager_pem::certificates(($row['certificate_pem'] ?? '')."\n".($row['certificate_chain'] ?? ''));
	if (empty($certificates)) {
		http_response_code(404);
		header('Content-Type: text/plain');
		exit;
	}
	header('Content-Type: application/pem-certificate-chain');
	header('Cache-Control: public, max-age=3600');
	header('X-Content-Type-Options: nosniff');
	echo implode('', $certificates);

?>
