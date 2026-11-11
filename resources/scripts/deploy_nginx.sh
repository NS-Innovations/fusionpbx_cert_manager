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
# Everything in the directories the service writes is read and written as
# www-data, never as root, as www-data owns them: a link or a special file
# planted there then reaches nothing www-data could not already reach, and every
# read is held to a size and a time.
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

#run a command as www-data, held to a time, for everything in the directories the service writes
	as_service() {
		timeout 20 runuser -u www-data -- "$@"
	}

#read a file the service wrote, as www-data, held to a size
	read_service_file() {
		as_service head -c "$2" -- "$1"
	}

#read the serial number the service asked for
	serial=$(read_service_file "$deploy/nginx.request" 128 2>/dev/null | tr -cd 'a-f0-9')

#write the result for the service, as www-data, through a new file moved over the old one
	write_result() {
		printf '%s %s %s\n' "$1" "$serial" "$2" | as_service sh -c 'umask 022; tmp=$(mktemp "$1/.nginx.result.XXXXXX") && cat > "$tmp" && mv -f "$tmp" "$1/nginx.result"' sh "$deploy"
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

#copy the files into the root owned directory under new names, read as www-data and held to a size
	umask 077
	if [ -L "$ssl" ]; then
		write_result failed "$ssl is a link"
		exit 1
	fi
	mkdir -p "$ssl"
	chmod 755 "$ssl"
	if ! read_service_file "$live/fullchain.pem" 1048576 > "$ssl/nginx.crt.new" 2>/dev/null || [ ! -s "$ssl/nginx.crt.new" ]; then
		rm -f "$ssl/nginx.crt.new"
		write_result failed "the certificate could not be read"
		exit 1
	fi
	if ! read_service_file "$live/privkey.pem" 65536 > "$ssl/nginx.key.new" 2>/dev/null || [ ! -s "$ssl/nginx.key.new" ]; then
		rm -f "$ssl/nginx.crt.new" "$ssl/nginx.key.new"
		write_result failed "the key could not be read"
		exit 1
	fi
	chmod 644 "$ssl/nginx.crt.new"
	chmod 600 "$ssl/nginx.key.new"

#check the copies are a certificate and the key that belongs to it, by their public keys, each read on its own so a
#file that is not one gives nothing rather than the output of a later command
	crt_pub=$(openssl x509 -in "$ssl/nginx.crt.new" -noout -pubkey 2>/dev/null) || crt_pub=
	key_pub=$(openssl pkey -in "$ssl/nginx.key.new" -pubout 2>/dev/null) || key_pub=
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

#put everything back as it was; the first time there is nothing before, so the links go back and the files nginx did
#not accept are removed, rather than kept to be taken as the files before on the next run
	put_back() {
		if [ -f "$ssl/nginx.crt.prev" ]; then
			mv -f "$ssl/nginx.crt.prev" "$ssl/nginx.crt"
			mv -f "$ssl/nginx.key.prev" "$ssl/nginx.key"
		else
			for link in "$nginx_crt" "$nginx_key"; do
				[ -e "$link.cert_manager.bak" ] || [ -L "$link.cert_manager.bak" ] && mv -f "$link.cert_manager.bak" "$link"
			done
			rm -f "$ssl/nginx.crt" "$ssl/nginx.key"
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
		if ! reload=$(systemctl reload nginx 2>&1); then
			rm -f "$ssl/fusionpbx.prev"
			write_result failed "nginx accepted the certificate but did not reload: $(printf '%s' "$reload" | tr -d '\n' | cut -c1-200)"
			exit 1
		fi
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
