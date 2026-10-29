#!/bin/sh
#
# FusionPBX certificate manager - install
# Version: MPL 1.1
#
# The contents of this file are subject to the Mozilla Public License Version
# 1.1 (the "License"); you may not use this file except in compliance with
# the License. You may obtain a copy of the License at
# http://www.mozilla.org/MPL/
#
# Software distributed under the License is distributed on an "AS IS" basis,
# WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
# for the specific language governing rights and limitations under the
# License.
#
# The Original Code is FusionPBX
#
# The Initial Developer of the Original Code is
# Mark J Crane <markjcrane@fusionpbx.com>
# Portions created by the Initial Developer are Copyright (C) 2008-2026
# the Initial Developer. All Rights Reserved.
#
# Contributor(s):
# Mark J Crane <markjcrane@fusionpbx.com>
# Norman King <norman@nsinnovations.net>
#
# Installs the certificate manager. Run it as root after cloning the app into
# /var/www/fusionpbx/app/cert_manager. Running it again is harmless, and is how
# an update of the nginx helper is installed.
#
#   sh /var/www/fusionpbx/app/cert_manager/resources/install.sh

	set -e

#only root can install the helper and the units
	if [ "$(id -u)" != "0" ]; then
		echo "run this as root"
		exit 1
	fi

#find the app and fusionpbx
	app=$(cd "$(dirname "$0")/.." && pwd)
	fusionpbx=$(cd "$app/../.." && pwd)

#the directories the service writes, as www-data
	install -d -o www-data -g www-data -m 750 /etc/fusionpbx/cert_manager
	install -d -o www-data -g www-data -m 750 /etc/fusionpbx/cert_manager/live
	install -d -o www-data -g www-data -m 750 /etc/fusionpbx/cert_manager/live/nginx
	install -d -o www-data -g www-data -m 750 /etc/fusionpbx/cert_manager/deploy
	install -d -o www-data -g www-data -m 755 /var/www/dehydrated
	if [ -d /etc/fusionpbx/certs ]; then
		chown www-data:www-data /etc/fusionpbx/certs
	else
		install -d -o www-data -g www-data -m 750 /etc/fusionpbx/certs
	fi

#the key that encrypts the stored secrets, created once and never replaced
	if [ ! -f /etc/fusionpbx/cert_manager.key ]; then
		(umask 027 && head -c 32 /dev/urandom | base64 > /etc/fusionpbx/cert_manager.key)
		chown root:www-data /etc/fusionpbx/cert_manager.key
		chmod 640 /etc/fusionpbx/cert_manager.key
		echo "created /etc/fusionpbx/cert_manager.key; back it up with the database"
	fi

#the root helper that gives nginx its certificate, as a root owned copy
	install -o root -g root -m 755 "$app/resources/scripts/deploy_nginx.sh" /usr/local/sbin/cert_manager_deploy_nginx
	install -o root -g root -m 644 "$app/resources/service/debian/cert_manager_nginx.path" /etc/systemd/system/cert_manager_nginx.path
	install -o root -g root -m 644 "$app/resources/service/debian/cert_manager_nginx.service" /etc/systemd/system/cert_manager_nginx.service
	systemctl daemon-reload
	systemctl enable --now cert_manager_nginx.path

#the schema, the menu, the permissions and the service of the app
	chown -R www-data:www-data "$app"
	cd "$fusionpbx"
	php core/upgrade/upgrade.php > /dev/null
	php core/upgrade/upgrade.php --menu default > /dev/null
	php core/upgrade/upgrade.php --group > /dev/null
	php core/upgrade/upgrade.php --service update > /dev/null

	echo "installed; open Advanced > Certificates"
