# fusionpbx-app-cert_manager

Manage the certificates of a FusionPBX server from its web UI: the TLS certificate of the web UI,
the TLS certificate of FreeSWITCH, and the STIR/SHAKEN signing certificate.

Certificates are ordered and renewed from any ACME certificate authority, such as Let's Encrypt,
for single names and wildcards. Wildcards are validated over DNS through pluggable DNS providers.
Certificates issued elsewhere, such as a STIR/SHAKEN certificate from an STI-CA, can be imported,
deployed and watched for expiry the same way.

---

## Features

- **Any ACME certificate authority.** Let's Encrypt, its staging environment, ZeroSSL and Google
  Trust Services are offered by name, and any other ACME directory can be entered by URL. The
  external account binding that ZeroSSL and Google require is supported.
- **Standard and wildcard certificates.** Names are validated over http-01, using the
  `/.well-known/acme-challenge` location the stock FusionPBX nginx config already has, or over
  dns-01, which a wildcard needs.
- **Pluggable DNS providers.** Cloudflare and Manual ship with the app. A new provider is one class
  file and its language keys; see [Adding a DNS provider](#adding-a-dns-provider).
- **Automatic renewal.** The service renews every ACME certificate a set number of days before it
  expires (30 by default) and deploys it again. A failing renewal backs off from an hour to a day,
  so it never runs into the rate limits of the authority.
- **Import.** Paste a certificate, its intermediates and its key. The names, dates and issuer are
  read from it, and the key has to match.
- **Deployments.** A certificate is written wherever it is used, again after every renewal:
  - **Web UI (nginx)**: through a small root helper that checks the files, reloads nginx and puts
    the previous certificate back if nginx refuses the new one.
  - **FreeSWITCH TLS**: the same files the FusionPBX installer writes to `/etc/freeswitch/tls`. A
    Restart button restarts the SIP profiles that use them, after a confirmation, since that drops
    their calls.
  - **STIR/SHAKEN (identity app)**: the key and certificate
    [fusionpbx-app-identity](https://github.com/fusionpbx/fusionpbx-app-identity) signs calls with.
- **x5u publishing.** A certificate can be served without a login at a public URL, which is what
  STIR/SHAKEN puts in the `x5u` of every signature.
- **Expiry warnings.** When `notify_email` is set, a warning is emailed at 30, 14, 7 and 1 days
  before a certificate expires without having been renewed, and when an order fails.
- **Secrets stay encrypted.** Private keys, account keys and DNS provider credentials are stored
  encrypted with a key that lives outside the database.
- **Everything through the web UI.** Accounts, providers, certificates, deployments, renewals and
  the order log are all pages.

---

## Requirements

- FusionPBX 5.5 or later on Debian, with nginx and systemd.
- PHP with the `openssl`, `curl` and `sodium` extensions, which FusionPBX already needs.
- For http-01, every name must resolve to this server and port 80 must be reachable from the
  internet. Port 80 may redirect to https, as the stock config does.
- For dns-01, a DNS provider the app supports, or the patience to add TXT records by hand.

---

## Install

```sh
cd /var/www/fusionpbx/app
git clone https://github.com/NS-Innovations/fusionpbx_cert_manager.git cert_manager
sh /var/www/fusionpbx/app/cert_manager/resources/install.sh
```

The install script needs root. It:

1. creates the directories the service writes, owned by `www-data`:
   `/etc/fusionpbx/cert_manager`, `/etc/fusionpbx/certs` and `/var/www/dehydrated`;
2. creates `/etc/fusionpbx/cert_manager.key`, the key that encrypts the stored secrets, once;
3. installs the nginx helper as `/usr/local/sbin/cert_manager_deploy_nginx` with
   `cert_manager_nginx.path` and `cert_manager_nginx.service`, and enables the path unit;
4. runs `core/upgrade/upgrade.php` for the schema, `--menu default`, `--group` and
   `--service update`, which installs and starts the `cert_manager` service.

Running it again is harmless, and is how an updated nginx helper is installed after a `git pull`.

**Back up `/etc/fusionpbx/cert_manager.key` along with the database.** Without it the stored keys
and credentials cannot be decrypted.

---

## Getting a certificate for the web UI

1. **Advanced > Certificates > ACME Accounts > Add.** Pick the certificate authority, enter a
   contact email, agree to the terms and save. The account is registered when it is saved. Use
   **Let's Encrypt Staging** first if you want to try things out; its certificates are not trusted
   by browsers, but its rate limits are generous.
2. For a wildcard, **DNS Providers > Add** and enter the credentials, then press **Test**.
3. **Certificates > Add.** Enter the names, one per line, choose the account, the validation, and
   the DNS provider for dns-01.
4. In **Deployments**, add **Web UI (nginx)** and save.

The service orders the certificate within a minute. The page shows its progress, and the log of
every step is kept on the certificate. Once it is issued it is handed to nginx, and the deployment
shows **Deployed** when nginx has reloaded with it.

The first deployment to nginx points `/etc/ssl/certs/nginx.crt` and `/etc/ssl/private/nginx.key`,
the paths the stock config reads, at `/etc/ssl/cert_manager/`. The links they had before are kept
beside them as `*.cert_manager.bak`.

## FreeSWITCH TLS

Add a **FreeSWITCH TLS** deployment to the same certificate, or to another one. The files are
written to `/etc/freeswitch/tls`; whatever was there before the first deployment is kept in
`/etc/freeswitch/tls/cert_manager.bak`.

Sofia only reads its certificates when a profile starts. Restarting a profile drops its calls, so
the app never does it by itself. The deployment shows **Restart needed** with a **Restart** button.
Put the profile names in **Options**, separated by commas, to choose which profiles it restarts;
left blank, it restarts every running profile that reports a TLS or WSS address.

TLS is off on a stock install. To turn it on for a profile, set `external_ssl_enable` or
`internal_ssl_enable` to `true` under **Advanced > Variables**, then clear the cache under
**Status > SIP Status** before restarting. FusionPBX serves `sofia.conf` from its cache, so without
that the profile restarts with the old value and does not listen for TLS.

## STIR/SHAKEN

STIR/SHAKEN certificates are issued by an STI-CA against an SPC token from the STI-PA, not by
Let's Encrypt, so they are imported:

1. **Certificates > Add**, Source **Import**. Paste the certificate followed by its intermediates,
   and its private key. SHAKEN signs with ES256, so the key has to be ECDSA P-256.
2. Set **Publish** to **True**. After saving, the page shows the public URL of the certificate.
3. Add a **STIR/SHAKEN (identity app)** deployment. It writes `identity_private.key` and
   `identity_certificate.pem` to `/etc/fusionpbx/certs`, the defaults of the identity app.
4. In `/etc/fusionpbx/config.conf`, set the identity app to that directory and that URL:

   ```
   identity.path = '/etc/fusionpbx/certs'
   identity.private_key_name = 'identity_private.key'
   identity.certificate_name = 'identity_certificate.pem'
   identity.certificate_url = 'https://pbx.example.com/app/cert_manager/x5u.php?id=<certificate uuid>'
   ```

An imported certificate cannot renew itself. Set `cert_manager > notify_email` so you are warned
in time to get a new one from the STI-CA and import it; the deployments then update on their own.

## DNS providers

| Provider   | Fields                     | Notes |
|------------|----------------------------|-------|
| Cloudflare | API Token, Zone ID         | The token needs **Zone > DNS > Edit** and **Zone > Zone > Read** on the zones of the certificates. The zone of each name is found automatically unless a Zone ID is given. |
| Manual     | none                       | The certificate page shows the TXT records to add at your DNS host. The order waits for them for as long as it is valid, up to a few days. |

Before answering a dns-01 challenge, the service checks that every resolver in
`cert_manager > dns_resolver` (Cloudflare and Google DNS over HTTPS by default) sees every record.
A record added through an API that is not seen within `propagation_timeout` fails the order.

### Adding a DNS provider

A provider is a class in `resources/classes/dns_provider_<type>.php` named `dns_provider_<type>`
that extends `dns_provider`. The registry finds it by its file name, and the DNS provider page
renders its fields; no other file changes.

```php
class dns_provider_example extends dns_provider {

	public static function fields(): array {
		return [
			['name' => 'api_key', 'type' => 'password', 'required' => true, 'secret' => true],
		];
	}

	public function test(): string {
		//call the api with $this->value('api_key'); throw an Exception when it is refused
		return 'the zones the key reaches';
	}

	public function create_txt(string $fqdn, string $value): string {
		//add the TXT record and return whatever delete_txt() needs to find it again
		return $record_id;
	}

	public function delete_txt(string $fqdn, string $record_id): void {
		//remove it; a record already gone is not an error
	}

}
```

Then add its language keys to `app_languages.php`:

```php
$text['option-dns_provider_example']['en-us'] = "Example DNS";
$text['label-dns_provider_example_api_key']['en-us'] = "API Key";
$text['description-dns_provider_example_api_key']['en-us'] = "Enter the API key.";
```

`dns_provider::http()` sends an HTTPS request and decodes the JSON answer. A field marked `secret` is
never shown again after it is saved, and a blank entry keeps the saved value. All the fields are
stored encrypted. `dns_provider_cloudflare.php` is a complete example.

---

## How it works

```
web UI (php-fpm, www-data)  --rows-->  database  <--polls--  cert_manager service (www-data)
                                                               | orders and renews over ACME
                                                               | adds and removes DNS records
                                                               | writes /etc/freeswitch/tls
                                                               | writes /etc/fusionpbx/certs
                                                               | writes /etc/fusionpbx/cert_manager/live/nginx
                                                               v
                                                  deploy/nginx.request
                                                               |
                                    cert_manager_nginx.path (root) -> cert_manager_deploy_nginx
                                    copies to /etc/ssl/cert_manager, checks, nginx -t, reload or roll back
```

- The web UI never touches the file system. It saves rows and sets flags.
- The `cert_manager` service runs as `www-data`. Each pass takes every certificate that needs it one
  step further, so a slow order, such as one waiting for a DNS record added by hand, never holds
  up the others. The state of an order is saved on the certificate after every step and survives
  a restart of the service.
- Only the nginx helper runs as root, and it only reads the two files the service wrote. It runs
  from a root owned copy in `/usr/local/sbin`, never from the web directory, which `www-data` can
  write. It copies the files into a root owned directory before checking them, so nginx never reads
  a path `www-data` can change.
- Every certificate gets a new key when it is ordered or renewed.

---

## Settings

Under **Advanced > Default Settings**, category `cert_manager`:

| Setting               | Default                                | Meaning |
|-----------------------|----------------------------------------|---------|
| `enabled`             | `true`                                 | Turns the service off without stopping it. |
| `interval`            | `60`                                   | Seconds between passes. A renewal asked for from the web UI starts within this time. |
| `renew_days`          | `30`                                   | Days before expiry an ACME certificate is renewed, unless it sets its own. Never earlier than half way through the life of the certificate. |
| `http_challenge_dir`  | `/var/www/dehydrated`                  | The directory nginx serves at `/.well-known/acme-challenge`. |
| `store_dir`           | `/etc/fusionpbx/cert_manager`          | Where the service hands certificates to the nginx helper. The helper has this path built in. |
| `key_file`            | `/etc/fusionpbx/cert_manager.key`      | The key that encrypts the stored secrets. |
| `freeswitch_tls_dir`  | `/etc/freeswitch/tls`                  | The TLS directory of FreeSWITCH. |
| `identity_dir`        | `/etc/fusionpbx/certs`                 | Where the identity app reads its key and certificate. |
| `dns_resolver`        | Cloudflare and Google DNS over HTTPS   | The resolvers that must all see a dns-01 record before it is answered. |
| `propagation_timeout` | `600`                                  | Seconds to wait for a record added through an API. |
| `warn_days`           | `30,14,7,1`                            | Days before expiry a warning is emailed. |
| `notify_email`        | disabled                               | Where warnings and failed renewals are emailed. Needs `email > smtp_from`. |
| `log_retention_days`  | `90`                                   | Days of the certificate log to keep. |

## Permissions

All of them are given to `superadmin` only, since the certificates belong to the server rather
than to a domain.

| Permission                         | Allows |
|------------------------------------|--------|
| `certificate_view` / `_add` / `_edit` / `_delete` | The certificates. |
| `certificate_renew`                | Renew now. |
| `certificate_private_key`          | Importing a private key. |
| `certificate_publish`              | Publishing a certificate at its x5u URL. |
| `certificate_deployment_view` / `_add` / `_edit` / `_delete` | The deployments of a certificate. |
| `certificate_deployment_restart`   | Restarting the SIP profiles of a FreeSWITCH deployment. |
| `certificate_log_view`             | The log of a certificate. |
| `acme_account_view` / `_add` / `_edit` / `_delete` | The ACME accounts. |
| `dns_provider_view` / `_add` / `_edit` / `_delete` | The DNS providers. |

---

## Security notes

- The encryption key is readable by `www-data`, because the web UI and the service need it. It
  protects copies of the database, such as backups, not a compromised web server.
- `x5u.php` serves only certificates that are enabled and published, never a key, and answers
  anything else with a plain 404.
- The private key field is never filled in when a certificate is shown again.

---

## Removal

```sh
cd /var/www/fusionpbx/app/cert_manager
php uninstall.php --confirm              # remove the app, its tables and its key file
php uninstall.php --confirm --keep-data  # remove the app but keep its tables and key file
rm -rf /var/www/fusionpbx/app/cert_manager
```

Run it as root. It removes the service, the nginx helper and its units, the menu entry, the
permissions and the settings. Certificates already deployed stay in place, so nginx, FreeSWITCH
and the identity app keep working. To point nginx back at the certificate it used before:

```sh
mv /etc/ssl/certs/nginx.crt.cert_manager.bak /etc/ssl/certs/nginx.crt
mv /etc/ssl/private/nginx.key.cert_manager.bak /etc/ssl/private/nginx.key
nginx -t && systemctl reload nginx
```

---

## License

Mozilla Public License 1.1, the same license as FusionPBX. See [LICENSE](LICENSE).
