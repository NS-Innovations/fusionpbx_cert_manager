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
	Removes everything this app added to the system and nothing else.

	What is removed is read out of app_config.php and app_menu.php rather than listed here, so this
	stays correct when the app gains a table, a permission or a menu entry.

	The certificates already deployed are left where they are, so nginx, FreeSWITCH and the identity app
	keep working with them. Run it as root, since it removes systemd units.

	Usage:
		php uninstall.php --confirm
		php uninstall.php --confirm --keep-data
*/

//this only runs from the command line
	if (php_sapi_name() !== 'cli') {
		echo "access denied\n";
		exit(1);
	}

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";

//read the command line options
	$options = getopt('', ['confirm', 'keep-data', 'help']);
	$keep_data = isset($options['keep-data']);

//show the help message
	if (isset($options['help']) || !isset($options['confirm'])) {
		echo "\n";
		echo "Removes the certificate manager app from this system.\n";
		echo "\n";
		echo "Usage:\n";
		echo "  php uninstall.php --confirm              Remove the app, drop its tables and its key file\n";
		echo "  php uninstall.php --confirm --keep-data  Remove the app but leave its tables and key file in place\n";
		echo "\n";
		echo "Removed either way: the service, the nginx helper and its units, the menu entries, the\n";
		echo "group permissions, and the default, domain and user settings of this app.\n";
		echo "\n";
		echo "The certificates already deployed stay where they are. Delete the app directory afterwards.\n";
		echo "\n";
		exit(isset($options['help']) ? 0 : 1);
	}

//read what this app installed
	$x = 0;
	$apps = [];
	include __DIR__ . '/app_config.php';
	include __DIR__ . '/app_menu.php';
	$app = $apps[0] ?? [];
	if (empty($app['uuid'])) {
		echo "could not read app_config.php\n";
		exit(1);
	}
	$app_uuid = $app['uuid'];
	$app_name = $app['name'];

//connect to the database
	$database = database::new();
	$settings = new settings(['database' => $database]);
	$key_file = $settings->get('cert_manager', 'key_file', '/etc/fusionpbx/cert_manager.key');
	$store_dir = $settings->get('cert_manager', 'store_dir', '/etc/fusionpbx/cert_manager');

	echo "\n";
	echo "Removing ".$app_name."\n";
	echo "\n";

//stop the service and the nginx helper, and remove them from the system
	foreach (['cert_manager.service', 'cert_manager_nginx.path', 'cert_manager_nginx.service'] as $unit) {
		$unit_file = '/etc/systemd/system/'.$unit;
		if (file_exists($unit_file)) {
			system("systemctl stop ".escapeshellarg($unit)." > /dev/null 2>&1");
			system("systemctl disable ".escapeshellarg($unit)." > /dev/null 2>&1");
			unlink($unit_file);
			echo "  removed ".$unit."\n";
		}
	}
	system("systemctl daemon-reload > /dev/null 2>&1");
	if (file_exists('/usr/local/sbin/cert_manager_deploy_nginx')) {
		unlink('/usr/local/sbin/cert_manager_deploy_nginx');
		echo "  removed the nginx helper\n";
	}

//remove the service record
	$sql = "delete from v_services where service_name = :service_name ";
	$parameters['service_name'] = 'cert_manager';
	$database->execute($sql, $parameters);
	unset($sql, $parameters);
	echo "  removed the service record\n";

//remove the menu entries of this app
	$menu_uuids = [];
	foreach ($app['menu'] ?? [] as $menu) {
		if (!empty($menu['uuid']) && is_uuid($menu['uuid'])) {
			$menu_uuids[] = "'".$menu['uuid']."'";
		}
	}
	if (@sizeof($menu_uuids) != 0) {
		//find the rows the menu entries created, which the groups and titles hang from
		$sql = "select menu_item_uuid from v_menu_items ";
		$sql .= "where uuid in (".implode(', ', $menu_uuids).") ";
		$rows = $database->select($sql, null, 'all');
		unset($sql);

		$item_uuids = [];
		if (is_array($rows) && @sizeof($rows) != 0) {
			foreach ($rows as $row) {
				if (is_uuid($row['menu_item_uuid'])) {
					$item_uuids[] = "'".$row['menu_item_uuid']."'";
				}
			}
		}
		unset($rows, $row);

		if (!empty($item_uuids)) {
			foreach (['v_menu_item_groups', 'v_menu_languages', 'v_menu_items'] as $table) {
				$sql = "delete from ".$table." where menu_item_uuid in (".implode(', ', $item_uuids).") ";
				$database->execute($sql, null);
				unset($sql);
			}
		}
		echo "  removed ".@sizeof($menu_uuids)." menu entries\n";
	}

//remove the group permissions of this app
	$permission_names = [];
	foreach ($app['permissions'] ?? [] as $permission) {
		if (!empty($permission['name'])) {
			$permission_names[] = $permission['name'];
		}
	}
	if (@sizeof($permission_names) != 0) {
		$placeholders = [];
		foreach ($permission_names as $index => $permission_name) {
			$placeholders[] = ':permission_'.$index;
			$parameters['permission_'.$index] = $permission_name;
		}
		$sql = "delete from v_group_permissions ";
		$sql .= "where permission_name in (".implode(', ', $placeholders).") ";
		$database->execute($sql, $parameters);
		unset($sql, $parameters, $placeholders);
		echo "  removed ".@sizeof($permission_names)." group permissions\n";
	}

//remove the settings of this app, which carry its app uuid
	foreach (['v_default_settings', 'v_domain_settings'] as $table) {
		$sql = "delete from ".$table." where app_uuid = :app_uuid ";
		$parameters['app_uuid'] = $app_uuid;
		$database->execute($sql, $parameters);
		unset($sql, $parameters);
	}

//user settings carry no app uuid, so they go by the category of this app
	$sql = "delete from v_user_settings where user_setting_category = :category ";
	$parameters['category'] = 'cert_manager';
	$database->execute($sql, $parameters);
	unset($sql, $parameters);
	echo "  removed the settings\n";

//drop the tables of this app unless the data was asked to be kept
	if ($keep_data) {
		echo "  kept the tables, their data and the key file\n";
	}
	else {
		//collect the tables and which of them reference each other
		$tables = [];
		$references = [];
		foreach ($app['db'] ?? [] as $definition) {
			$table = $definition['table']['name'] ?? '';
			if ($table === '') {
				continue;
			}
			$tables[] = $table;
			$references[$table] = [];
			foreach ($definition['fields'] ?? [] as $field) {
				$referenced = $field['key']['reference']['table'] ?? '';
				if ($referenced !== '' && $referenced !== $table) {
					$references[$table][] = $referenced;
				}
			}
		}

		//drop a table only once nothing that is left still points at it
		$remaining = $tables;
		$dropped = [];
		while (@sizeof($remaining) != 0) {
			$next = null;
			foreach ($remaining as $table) {
				$referenced_by_remaining = false;
				foreach ($remaining as $other) {
					if ($other !== $table && in_array($table, $references[$other], true)) {
						$referenced_by_remaining = true;
						break;
					}
				}
				if (!$referenced_by_remaining) {
					$next = $table;
					break;
				}
			}

			//a loop between the tables would leave nothing safe to drop first
			if ($next === null) {
				$next = $remaining[0];
			}

			$database->execute("drop table if exists ".$next, null);
			$dropped[] = $next;
			$remaining = array_values(array_diff($remaining, [$next]));
		}
		echo "  dropped ".@sizeof($dropped)." tables\n";

		//without the tables the key file and the copies the service wrote for nginx are of no use
		if (!empty($key_file) && file_exists($key_file)) {
			unlink($key_file);
			echo "  removed the key file\n";
		}
		if (!empty($store_dir) && is_dir($store_dir)) {
			system("rm -rf ".escapeshellarg($store_dir));
			echo "  removed ".$store_dir."\n";
		}
	}

	echo "\n";
	echo "Done. nginx still uses the certificate last deployed, from /etc/ssl/cert_manager. To go back\n";
	echo "to the certificate it used before the app, put the links it kept back and reload nginx:\n";
	echo "  mv /etc/ssl/certs/nginx.crt.cert_manager.bak /etc/ssl/certs/nginx.crt\n";
	echo "  mv /etc/ssl/private/nginx.key.cert_manager.bak /etc/ssl/private/nginx.key\n";
	echo "  [ -f /etc/nginx/sites-available/fusionpbx.cert_manager.bak ] && mv /etc/nginx/sites-available/fusionpbx.cert_manager.bak /etc/nginx/sites-available/fusionpbx\n";
	echo "  nginx -t && systemctl reload nginx\n";
	echo "\n";
	echo "The app directory can now be deleted:\n";
	echo "  rm -rf ".__DIR__."\n";
	echo "\n";

?>
