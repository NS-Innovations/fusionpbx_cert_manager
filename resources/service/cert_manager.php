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

require_once dirname(__DIR__, 4) . '/resources/require.php';

try {
	//create the service
	$service = cert_manager_service::create();

	//exit with the status the run method returns
	exit($service->run());
} catch (Throwable $ex) {
	//show the details of the error
	echo "Error occurred in " . $ex->getFile() . ' (' . $ex->getLine() . '):' . $ex->getMessage();

	//exit with an error status, the code of a database error being a text such as 08006
	exit(is_int($ex->getCode()) && $ex->getCode() > 0 ? $ex->getCode() : 1);
}
