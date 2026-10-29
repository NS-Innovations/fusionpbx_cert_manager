#!/bin/sh
#
# FusionPBX certificate manager - nginx deployment helper
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
# Runs as root from cert_manager_nginx.service whenever the certificate manager
# service, which runs as www-data, asks for nginx to use a new certificate.
#
# It first checks that nginx reads its certificate from the paths it manages.
# When it does not, as after the installer's letsencrypt.sh, the certificate
# lines of the FusionPBX site are pointed back at those paths, keeping the file
# as it was beside it. It then only ever reads the two files the service wrote, copies them into a
# directory only root can write once they prove to be a matching certificate
# and key, points nginx at those copies, and reloads nginx. When nginx does not
# accept the new certificate, everything is put back as it was. The result is
# written back for the service to read.
#
# Install a root owned copy; never run it from the web directory, which
# www-data can write:
#   install -o root -g root -m 755 deploy_nginx.sh /usr/local/sbin/cert_manager_deploy_nginx

#set the paths
	store=/etc/fusionpbx/cert_manager
	live=$store/live/nginx
	deploy=$store/deploy
	ssl=/etc/ssl/cert_manager
	nginx_crt=/etc/ssl/certs/nginx.crt
	nginx_key=/etc/ssl/private/nginx.key
	site=/etc/nginx/sites-available/fusionpbx

#read the serial number the service asked for
	serial=$(head -c 128 "$deploy/nginx.request" 2>/dev/null | tr -cd 'a-f0-9')

#write the result for the service, through a new file so a link planted at the name is replaced, not followed
	write_result() {
		tmp=$(mktemp "$deploy/.nginx.result.XXXXXX") || exit 1
		printf '%s %s %s\n' "$1" "$serial" "$2" > "$tmp"
		chown www-data:www-data "$tmp"
		chmod 644 "$tmp"
		mv -f "$tmp" "$deploy/nginx.result"
	}

#list the certificate and key paths in the nginx configuration files, without comments or quotes
	read_paths() {
		paths=$(printf '%s\n' "$1" | awk '{ sub(/^[ \t]+/, "") } /^#/ { next } $1 == "ssl_certificate" || $1 == "ssl_certificate_key" { v = $2; sub(/;.*$/, "", v); gsub(/["\047]/, "", v); print $1 " " v }')
		managed="ssl_certificate $nginx_crt
ssl_certificate $ssl/nginx.crt
ssl_certificate_key $nginx_key
ssl_certificate_key $ssl/nginx.key"
		other=$(printf '%s\n' "$paths" | grep -vxF "$managed" | head -n 1 | cut -d ' ' -f 2)
	}
	reads_managed() {
		printf '%s\n' "$paths" | grep -qxF -e "ssl_certificate $nginx_crt" -e "ssl_certificate $ssl/nginx.crt"
	}
	if ! config=$(nginx -T 2>&1); then
		write_result failed "nginx did not accept its configuration: $(printf '%s' "$config" | grep -m1 emerg | tr -d '\n' | cut -c1-200)"
		exit 1
	fi
	read_paths "$config"

#when nginx reads its certificate from somewhere else, as it does after the installer's letsencrypt.sh, the site has to be rewritten, since a reload would change nothing
	rewrite=
	if ! reads_managed; then
		if [ ! -f "$site" ] || ! grep -Eq '^[[:space:]]*ssl_certificate[[:space:]]' "$site"; then
			write_result failed "nginx does not read $nginx_crt${other:+ (it reads $other)}, and $site has no ssl_certificate to point at it; point ssl_certificate and ssl_certificate_key at $nginx_crt and $nginx_key"
			exit 1
		fi
		rewrite=true
	fi

#copy the files into the root owned directory under new names
	umask 077
	mkdir -p "$ssl"
	chmod 755 "$ssl"
	cat "$live/fullchain.pem" > "$ssl/nginx.crt.new" 2>/dev/null || { write_result failed "the certificate could not be read"; exit 1; }
	cat "$live/privkey.pem" > "$ssl/nginx.key.new" 2>/dev/null || { write_result failed "the key could not be read"; exit 1; }
	chmod 644 "$ssl/nginx.crt.new"
	chmod 600 "$ssl/nginx.key.new"

#check the copies are a certificate and the key that belongs to it
	crt_pub=$(openssl x509 -in "$ssl/nginx.crt.new" -noout -pubkey 2>/dev/null | openssl sha256)
	key_pub=$(openssl pkey -in "$ssl/nginx.key.new" -pubout 2>/dev/null | openssl sha256)
	if [ -z "$crt_pub" ] || [ "$crt_pub" != "$key_pub" ]; then
		rm -f "$ssl/nginx.crt.new" "$ssl/nginx.key.new"
		write_result failed "the key does not belong to the certificate"
		exit 1
	fi

#keep the files nginx used until now
	[ -f "$ssl/nginx.crt" ] && cp -p "$ssl/nginx.crt" "$ssl/nginx.crt.prev"
	[ -f "$ssl/nginx.key" ] && cp -p "$ssl/nginx.key" "$ssl/nginx.key.prev"
	mv -f "$ssl/nginx.crt.new" "$ssl/nginx.crt"
	mv -f "$ssl/nginx.key.new" "$ssl/nginx.key"

#point the certificate lines of the site at the managed paths, keeping the file as it was before the first time
	if [ -n "$rewrite" ]; then
		cp -p "$site" "$ssl/fusionpbx.prev"
		[ -e "$site.cert_manager.bak" ] || cp -p "$site" "$site.cert_manager.bak"
		sed -E -i --follow-symlinks \
			-e "s@^([[:space:]]*)ssl_certificate[[:space:]]+[^;]*;@\\1ssl_certificate         $nginx_crt;@" \
			-e "s@^([[:space:]]*)ssl_certificate_key[[:space:]]+[^;]*;@\\1ssl_certificate_key     $nginx_key;@" \
			"$site"
	fi

#point nginx at the copies the first time, keeping where it pointed before
	for pair in "$nginx_crt:$ssl/nginx.crt" "$nginx_key:$ssl/nginx.key"; do
		link=${pair%%:*}
		target=${pair#*:}
		if [ "$(readlink "$link")" != "$target" ]; then
			if [ -e "$link" ] || [ -L "$link" ]; then
				[ -e "$link.cert_manager.bak" ] || [ -L "$link.cert_manager.bak" ] || cp -P "$link" "$link.cert_manager.bak"
			fi
			ln -sfn "$target" "$link"
		fi
	done

#put everything back as it was
	put_back() {
		if [ -f "$ssl/nginx.crt.prev" ]; then
			mv -f "$ssl/nginx.crt.prev" "$ssl/nginx.crt"
			mv -f "$ssl/nginx.key.prev" "$ssl/nginx.key"
		else
			for link in "$nginx_crt" "$nginx_key"; do
				[ -e "$link.cert_manager.bak" ] || [ -L "$link.cert_manager.bak" ] && mv -f "$link.cert_manager.bak" "$link"
			done
		fi
		if [ -n "$rewrite" ]; then
			cp -p "$ssl/fusionpbx.prev" "$site"
			rm -f "$ssl/fusionpbx.prev"
		fi
	}

#check nginx reads the managed paths now the site is rewritten, which it does not when the site is not enabled
	if [ -n "$rewrite" ] && config=$(nginx -T 2>&1); then
		read_paths "$config"
		if ! reads_managed; then
			put_back
			write_result failed "nginx does not read $nginx_crt${other:+ (it reads $other)}, even with $site pointed at it; point ssl_certificate and ssl_certificate_key at $nginx_crt and $nginx_key"
			exit 1
		fi
	fi

#reload nginx, or put everything back when it does not accept the certificate
	if output=$(nginx -t 2>&1); then
		systemctl reload nginx
		if [ -n "$rewrite" ]; then
			rm -f "$ssl/fusionpbx.prev"
			write_result ok "nginx reloaded; $site now reads $nginx_crt and $nginx_key, and was kept as it was in $site.cert_manager.bak${other:+, but some server blocks still read $other}"
		else
			write_result ok "nginx reloaded${other:+, but some server blocks still read $other}"
		fi
	else
		put_back
		write_result failed "nginx did not accept the certificate: $(printf '%s' "$output" | grep -m1 emerg | tr -d '\n' | cut -c1-200)"
		exit 1
	fi
