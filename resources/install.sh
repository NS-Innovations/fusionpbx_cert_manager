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
# /var/www/fusionpbx/app/cert_manager. Running it again is harmless.
#
# The nginx helper runs as root, so a copy of it that differs from the one
# installed is only installed when asked for, after its changes are shown:
# whoever can write the app directory could otherwise have root run anything.
#
#   sh /var/www/fusionpbx/app/cert_manager/resources/install.sh
#   sh /var/www/fusionpbx/app/cert_manager/resources/install.sh --update-helper

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

#the root helper that gives nginx its certificate, as a root owned copy; a changed copy is shown, and installed only
#with --update-helper
	helper=/usr/local/sbin/cert_manager_deploy_nginx
	if [ -f "$helper" ] && ! cmp -s "$app/resources/scripts/deploy_nginx.sh" "$helper"; then
		if [ "$1" != "--update-helper" ]; then
			diff -u "$helper" "$app/resources/scripts/deploy_nginx.sh" || true
			echo "the nginx helper in the app differs from the one installed, shown above; it runs as root, so"
			echo "read the changes, then run this again with --update-helper to install it"
			exit 1
		fi
	fi
	install -o root -g root -m 755 "$app/resources/scripts/deploy_nginx.sh" "$helper"
	for unit in cert_manager_nginx.path cert_manager_nginx.service; do
		if [ -f "/etc/systemd/system/$unit" ] && ! cmp -s "$app/resources/service/debian/$unit" "/etc/systemd/system/$unit" && [ "$1" != "--update-helper" ]; then
			diff -u "/etc/systemd/system/$unit" "$app/resources/service/debian/$unit" || true
			echo "the unit $unit differs from the one installed, shown above; run this again with --update-helper to install it"
			exit 1
		fi
		install -o root -g root -m 644 "$app/resources/service/debian/$unit" "/etc/systemd/system/$unit"
	done
	systemctl daemon-reload
	systemctl enable --now cert_manager_nginx.path

#the schema, the menu and the permissions of the app, as www-data, since these run the code of every app; only the
#service, which writes the system's units, is updated as root
	cd "$fusionpbx"
	runuser -u www-data -- php core/upgrade/upgrade.php > /dev/null
	runuser -u www-data -- php core/upgrade/upgrade.php --menu default > /dev/null
	runuser -u www-data -- php core/upgrade/upgrade.php --group > /dev/null
	php core/upgrade/upgrade.php --service update > /dev/null

	echo "installed; open Advanced > Certificates"
