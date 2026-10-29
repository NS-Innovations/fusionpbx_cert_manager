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

//process one time
	if ($domains_processed == 1) {

		//create the key that encrypts the stored secrets, when it is missing and its directory can be written
			$key_file = $settings->get('cert_manager', 'key_file', '/etc/fusionpbx/cert_manager.key');
			if (!empty($key_file) && !file_exists($key_file) && is_writable(dirname($key_file))) {
				$old_umask = umask(0027);
				if (file_put_contents($key_file, cert_manager_crypto::new_key() . "\n", LOCK_EX) !== false) {
					chmod($key_file, 0640);
					@chgrp($key_file, 'www-data');
				}
				umask($old_umask);
			}
			unset($key_file, $old_umask);

	}

?>
