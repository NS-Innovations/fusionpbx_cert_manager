<?php

$text['title-acme_accounts']['en-us'] = "ACME Accounts";
$text['title-acme_accounts']['en-gb'] = "ACME Accounts";

$text['title-acme_account']['en-us'] = "ACME Account";
$text['title-acme_account']['en-gb'] = "ACME Account";

$text['description-acme_accounts']['en-us'] = "An ACME account registers this server with a certificate authority such as Let's Encrypt. Every certificate ordered over ACME is ordered through one of these accounts.";
$text['description-acme_accounts']['en-gb'] = "An ACME account registers this server with a certificate authority such as Let's Encrypt. Every certificate ordered over ACME is ordered through one of these accounts.";

$text['description-acme_account']['en-us'] = "The account is registered with the certificate authority when it is saved, and its contact is brought up to date on every later save.";
$text['description-acme_account']['en-gb'] = "The account is registered with the certificate authority when it is saved, and its contact is brought up to date on every later save.";

$text['label-acme_account_directory']['en-us'] = "Certificate Authority";
$text['label-acme_account_directory']['en-gb'] = "Certificate Authority";

$text['label-acme_account_eab_kid']['en-us'] = "EAB Key ID";
$text['label-acme_account_eab_kid']['en-gb'] = "EAB Key ID";

$text['label-acme_account_eab_hmac']['en-us'] = "EAB HMAC Key";
$text['label-acme_account_eab_hmac']['en-gb'] = "EAB HMAC Key";

$text['label-email']['en-us'] = "Email";
$text['label-email']['en-gb'] = "Email";

$text['label-unchanged']['en-us'] = "Unchanged";
$text['label-unchanged']['en-gb'] = "Unchanged";

$text['option-letsencrypt']['en-us'] = "Let's Encrypt";
$text['option-letsencrypt']['en-gb'] = "Let's Encrypt";

$text['option-letsencrypt_staging']['en-us'] = "Let's Encrypt Staging";
$text['option-letsencrypt_staging']['en-gb'] = "Let's Encrypt Staging";

$text['option-zerossl']['en-us'] = "ZeroSSL";
$text['option-zerossl']['en-gb'] = "ZeroSSL";

$text['option-google']['en-us'] = "Google Trust Services";
$text['option-google']['en-gb'] = "Google Trust Services";

$text['option-custom']['en-us'] = "Custom";
$text['option-custom']['en-gb'] = "Custom";

$text['option-status_unregistered']['en-us'] = "Not registered";
$text['option-status_unregistered']['en-gb'] = "Not registered";

$text['option-status_valid']['en-us'] = "Valid";
$text['option-status_valid']['en-gb'] = "Valid";

$text['option-status_deactivated']['en-us'] = "Deactivated";
$text['option-status_deactivated']['en-gb'] = "Deactivated";

$text['option-status_revoked']['en-us'] = "Revoked";
$text['option-status_revoked']['en-gb'] = "Revoked";

$text['description-acme_account_name']['en-us'] = "Enter the name of the account.";
$text['description-acme_account_name']['en-gb'] = "Enter the name of the account.";

$text['description-acme_account_directory']['en-us'] = "Select the certificate authority, or select Custom and enter the URL of its ACME directory. Let's Encrypt Staging issues certificates that browsers do not trust and is meant for testing.";
$text['description-acme_account_directory']['en-gb'] = "Select the certificate authority, or select Custom and enter the URL of its ACME directory. Let's Encrypt Staging issues certificates that browsers do not trust and is meant for testing.";

$text['description-acme_account_email']['en-us'] = "Enter the contact email of the account. The certificate authority may send notices about the account to it.";
$text['description-acme_account_email']['en-gb'] = "Enter the contact email of the account. The certificate authority may send notices about the account to it.";

$text['description-acme_account_eab_kid']['en-us'] = "Enter the key ID of the external account binding. ZeroSSL and Google Trust Services require one, and give it in their dashboard.";
$text['description-acme_account_eab_kid']['en-gb'] = "Enter the key ID of the external account binding. ZeroSSL and Google Trust Services require one, and give it in their dashboard.";

$text['description-acme_account_eab_hmac']['en-us'] = "Enter the HMAC key of the external account binding. It is stored encrypted. Leave it blank to keep the saved key.";
$text['description-acme_account_eab_hmac']['en-gb'] = "Enter the HMAC key of the external account binding. It is stored encrypted. Leave it blank to keep the saved key.";

$text['description-acme_account_enabled']['en-us'] = "Select whether the account is enabled. Certificates of a disabled account are not renewed.";
$text['description-acme_account_enabled']['en-gb'] = "Select whether the account is enabled. Certificates of a disabled account are not renewed.";

$text['description-acme_account_description']['en-us'] = "Enter the description.";
$text['description-acme_account_description']['en-gb'] = "Enter the description.";

$text['message-acme_account_in_use']['en-us'] = "An account that a certificate uses cannot be deleted.";
$text['message-acme_account_in_use']['en-gb'] = "An account that a certificate uses cannot be deleted.";

$text['message-acme_account_directory_invalid']['en-us'] = "The certificate authority must be an https URL.";
$text['message-acme_account_directory_invalid']['en-gb'] = "The certificate authority must be an https URL.";

$text['message-acme_account_email_invalid']['en-us'] = "The email address is not valid.";
$text['message-acme_account_email_invalid']['en-gb'] = "The email address is not valid.";

$text['message-key_file_missing']['en-us'] = "The key file that encrypts the stored secrets cannot be read. Run the install script of the app as root.";
$text['message-key_file_missing']['en-gb'] = "The key file that encrypts the stored secrets cannot be read. Run the install script of the app as root.";

$text['label-acme_account_agree']['en-us'] = "Terms of Service";
$text['label-acme_account_agree']['en-gb'] = "Terms of Service";

$text['option-acme_account_agree']['en-us'] = "I agree to the terms of service of the certificate authority";
$text['option-acme_account_agree']['en-gb'] = "I agree to the terms of service of the certificate authority";

$text['label-acme_account_terms']['en-us'] = "Read the terms";
$text['label-acme_account_terms']['en-gb'] = "Read the terms";

$text['description-acme_account_agree']['en-us'] = "The certificate authority only registers an account whose owner agrees to its terms of service.";
$text['description-acme_account_agree']['en-gb'] = "The certificate authority only registers an account whose owner agrees to its terms of service.";

$text['message-acme_account_agree_required']['en-us'] = "The terms of service of the certificate authority must be agreed to before the account can be registered.";
$text['message-acme_account_agree_required']['en-gb'] = "The terms of service of the certificate authority must be agreed to before the account can be registered.";

$text['message-acme_account_registered']['en-us'] = "The account is registered with the certificate authority.";
$text['message-acme_account_registered']['en-gb'] = "The account is registered with the certificate authority.";

$text['message-acme_account_register_failed']['en-us'] = "The certificate authority did not register the account:";
$text['message-acme_account_register_failed']['en-gb'] = "The certificate authority did not register the account:";

$text['option-dns_provider_manual']['en-us'] = "Manual";
$text['option-dns_provider_manual']['en-gb'] = "Manual";

$text['option-dns_provider_cloudflare']['en-us'] = "Cloudflare";
$text['option-dns_provider_cloudflare']['en-gb'] = "Cloudflare";

$text['label-dns_provider_cloudflare_api_token']['en-us'] = "API Token";
$text['label-dns_provider_cloudflare_api_token']['en-gb'] = "API Token";

$text['description-dns_provider_cloudflare_api_token']['en-us'] = "Enter a Cloudflare API token with the Zone > DNS > Edit and Zone > Zone > Read permissions for the zones of the certificates. It is stored encrypted. Leave it blank to keep the saved token.";
$text['description-dns_provider_cloudflare_api_token']['en-gb'] = "Enter a Cloudflare API token with the Zone > DNS > Edit and Zone > Zone > Read permissions for the zones of the certificates. It is stored encrypted. Leave it blank to keep the saved token.";

$text['label-dns_provider_cloudflare_zone_id']['en-us'] = "Zone ID";
$text['label-dns_provider_cloudflare_zone_id']['en-gb'] = "Zone ID";

$text['description-dns_provider_cloudflare_zone_id']['en-us'] = "Optional. Enter the zone ID to use it for every name. Leave it blank to find the zone of each name automatically.";
$text['description-dns_provider_cloudflare_zone_id']['en-gb'] = "Optional. Enter the zone ID to use it for every name. Leave it blank to find the zone of each name automatically.";

$text['title-dns_providers']['en-us'] = "DNS Providers";
$text['title-dns_providers']['en-gb'] = "DNS Providers";

$text['title-dns_provider']['en-us'] = "DNS Provider";
$text['title-dns_provider']['en-gb'] = "DNS Provider";

$text['description-dns_providers']['en-us'] = "A DNS provider publishes the TXT records that prove control of a name over dns-01, which a wildcard certificate requires.";
$text['description-dns_providers']['en-gb'] = "A DNS provider publishes the TXT records that prove control of a name over dns-01, which a wildcard certificate requires.";

$text['description-dns_provider']['en-us'] = "Select the provider and enter its credentials. Test saves the provider and checks that the credentials work.";
$text['description-dns_provider']['en-gb'] = "Select the provider and enter its credentials. Test saves the provider and checks that the credentials work.";

$text['description-dns_provider_name']['en-us'] = "Enter the name of the DNS provider.";
$text['description-dns_provider_name']['en-gb'] = "Enter the name of the DNS provider.";

$text['description-dns_provider_type']['en-us'] = "Select where the DNS of the names is hosted.";
$text['description-dns_provider_type']['en-gb'] = "Select where the DNS of the names is hosted.";

$text['description-dns_provider_manual']['en-us'] = "The certificate page shows the TXT records an order needs. Add them at your DNS host; the order carries on once they are published.";
$text['description-dns_provider_manual']['en-gb'] = "The certificate page shows the TXT records an order needs. Add them at your DNS host; the order carries on once they are published.";

$text['description-dns_provider_enabled']['en-us'] = "Select whether the DNS provider is enabled. Certificates using a disabled provider are not renewed.";
$text['description-dns_provider_enabled']['en-gb'] = "Select whether the DNS provider is enabled. Certificates using a disabled provider are not renewed.";

$text['description-dns_provider_description']['en-us'] = "Enter the description.";
$text['description-dns_provider_description']['en-gb'] = "Enter the description.";

$text['button-test']['en-us'] = "Test";
$text['button-test']['en-gb'] = "Test";

$text['message-dns_provider_in_use']['en-us'] = "A DNS provider that a certificate uses cannot be deleted.";
$text['message-dns_provider_in_use']['en-gb'] = "A DNS provider that a certificate uses cannot be deleted.";

$text['message-dns_provider_test_passed']['en-us'] = "The DNS provider works.";
$text['message-dns_provider_test_passed']['en-gb'] = "The DNS provider works.";

$text['message-dns_provider_test_failed']['en-us'] = "The DNS provider does not work:";
$text['message-dns_provider_test_failed']['en-gb'] = "The DNS provider does not work:";

$text['title-certificates']['en-us'] = "Certificates";
$text['title-certificates']['en-gb'] = "Certificates";

$text['title-certificate']['en-us'] = "Certificate";
$text['title-certificate']['en-gb'] = "Certificate";

$text['description-certificates']['en-us'] = "The certificates of this server. Certificates ordered over ACME are renewed before they expire and deployed wherever they are used.";
$text['description-certificates']['en-gb'] = "The certificates of this server. Certificates ordered over ACME are renewed before they expire and deployed wherever they are used.";

$text['description-certificate']['en-us'] = "The certificate is ordered by the certificate manager service shortly after it is saved, and again whenever the names or the key type change.";
$text['description-certificate']['en-gb'] = "The certificate is ordered by the certificate manager service shortly after it is saved, and again whenever the names or the key type change.";

$text['label-certificate_domains']['en-us'] = "Names";
$text['label-certificate_domains']['en-gb'] = "Names";

$text['label-certificate_source']['en-us'] = "Source";
$text['label-certificate_source']['en-gb'] = "Source";

$text['label-certificate_issuer']['en-us'] = "Issuer";
$text['label-certificate_issuer']['en-gb'] = "Issuer";

$text['label-certificate_expires']['en-us'] = "Expires";
$text['label-certificate_expires']['en-gb'] = "Expires";

$text['label-certificate_serial']['en-us'] = "Serial Number";
$text['label-certificate_serial']['en-gb'] = "Serial Number";

$text['label-certificate_validity']['en-us'] = "Valid";
$text['label-certificate_validity']['en-gb'] = "Valid";

$text['label-certificate_challenge']['en-us'] = "Validation";
$text['label-certificate_challenge']['en-gb'] = "Validation";

$text['label-certificate_key_type']['en-us'] = "Key Type";
$text['label-certificate_key_type']['en-gb'] = "Key Type";

$text['label-certificate_renew_days']['en-us'] = "Renew Days";
$text['label-certificate_renew_days']['en-gb'] = "Renew Days";

$text['label-certificate_next_attempt']['en-us'] = "Next attempt:";
$text['label-certificate_next_attempt']['en-gb'] = "Next attempt:";

$text['label-acme_account']['en-us'] = "ACME Account";
$text['label-acme_account']['en-gb'] = "ACME Account";

$text['label-dns_provider']['en-us'] = "DNS Provider";
$text['label-dns_provider']['en-gb'] = "DNS Provider";

$text['label-days']['en-us'] = "days";
$text['label-days']['en-gb'] = "days";

$text['button-renew']['en-us'] = "Renew";
$text['button-renew']['en-gb'] = "Renew";

$text['modal_title-renew']['en-us'] = "Renew";
$text['modal_title-renew']['en-gb'] = "Renew";

$text['modal_message-renew']['en-us'] = "Order the selected certificates again now?";
$text['modal_message-renew']['en-gb'] = "Order the selected certificates again now?";

$text['option-certificate_source_acme']['en-us'] = "ACME";
$text['option-certificate_source_acme']['en-gb'] = "ACME";

$text['option-certificate_source_import']['en-us'] = "Imported";
$text['option-certificate_source_import']['en-gb'] = "Imported";

$text['option-certificate_status_pending']['en-us'] = "Waiting to be ordered";
$text['option-certificate_status_pending']['en-gb'] = "Waiting to be ordered";

$text['option-certificate_status_ordering']['en-us'] = "Ordering";
$text['option-certificate_status_ordering']['en-gb'] = "Ordering";

$text['option-certificate_status_waiting_dns']['en-us'] = "Waiting for DNS records";
$text['option-certificate_status_waiting_dns']['en-gb'] = "Waiting for DNS records";

$text['option-certificate_status_valid']['en-us'] = "Valid";
$text['option-certificate_status_valid']['en-gb'] = "Valid";

$text['option-certificate_status_failed']['en-us'] = "Failed";
$text['option-certificate_status_failed']['en-gb'] = "Failed";

$text['option-certificate_status_expired']['en-us'] = "Expired";
$text['option-certificate_status_expired']['en-gb'] = "Expired";

$text['option-certificate_challenge_http_01']['en-us'] = "HTTP (http-01)";
$text['option-certificate_challenge_http_01']['en-gb'] = "HTTP (http-01)";

$text['option-certificate_challenge_dns_01']['en-us'] = "DNS (dns-01)";
$text['option-certificate_challenge_dns_01']['en-gb'] = "DNS (dns-01)";

$text['option-key_type_ec_256']['en-us'] = "ECDSA P-256";
$text['option-key_type_ec_256']['en-gb'] = "ECDSA P-256";

$text['option-key_type_ec_384']['en-us'] = "ECDSA P-384";
$text['option-key_type_ec_384']['en-gb'] = "ECDSA P-384";

$text['option-key_type_rsa_2048']['en-us'] = "RSA 2048";
$text['option-key_type_rsa_2048']['en-gb'] = "RSA 2048";

$text['option-key_type_rsa_4096']['en-us'] = "RSA 4096";
$text['option-key_type_rsa_4096']['en-gb'] = "RSA 4096";

$text['description-certificate_name']['en-us'] = "Enter the name of the certificate.";
$text['description-certificate_name']['en-gb'] = "Enter the name of the certificate.";

$text['description-certificate_domains']['en-us'] = "Enter the names the certificate covers, one per line. A wildcard such as *.example.com needs DNS validation.";
$text['description-certificate_domains']['en-gb'] = "Enter the names the certificate covers, one per line. A wildcard such as *.example.com needs DNS validation.";

$text['description-certificate_challenge']['en-us'] = "Select how the certificate authority checks that this server controls the names. HTTP needs port 80 reachable from the internet at every name. DNS adds a TXT record through the DNS provider and is the only way to validate a wildcard.";
$text['description-certificate_challenge']['en-gb'] = "Select how the certificate authority checks that this server controls the names. HTTP needs port 80 reachable from the internet at every name. DNS adds a TXT record through the DNS provider and is the only way to validate a wildcard.";

$text['description-certificate_key_type']['en-us'] = "Select the type of the private key. ECDSA P-256 suits nearly everything; choose RSA for old clients that need it.";
$text['description-certificate_key_type']['en-gb'] = "Select the type of the private key. ECDSA P-256 suits nearly everything; choose RSA for old clients that need it.";

$text['description-certificate_renew_days']['en-us'] = "Enter how many days before it expires the certificate is renewed. Leave it blank to use the default setting.";
$text['description-certificate_renew_days']['en-gb'] = "Enter how many days before it expires the certificate is renewed. Leave it blank to use the default setting.";

$text['description-certificate_enabled']['en-us'] = "Select whether the certificate is enabled. A disabled certificate is neither renewed nor deployed.";
$text['description-certificate_enabled']['en-gb'] = "Select whether the certificate is enabled. A disabled certificate is neither renewed nor deployed.";

$text['description-certificate_description']['en-us'] = "Enter the description.";
$text['description-certificate_description']['en-gb'] = "Enter the description.";

$text['message-certificate_domain_invalid']['en-us'] = "This name is not valid:";
$text['message-certificate_domain_invalid']['en-gb'] = "This name is not valid:";

$text['message-certificate_wildcard_dns']['en-us'] = "A wildcard name needs DNS validation.";
$text['message-certificate_wildcard_dns']['en-gb'] = "A wildcard name needs DNS validation.";

$text['message-certificate_renew_days_invalid']['en-us'] = "The renew days must be a whole number of at least 1.";
$text['message-certificate_renew_days_invalid']['en-gb'] = "The renew days must be a whole number of at least 1.";

$text['message-certificate_renew_requested']['en-us'] = "The certificate will be ordered by the certificate manager service.";
$text['message-certificate_renew_requested']['en-gb'] = "The certificate will be ordered by the certificate manager service.";

$text['description-certificate_acme_account']['en-us'] = "Select the ACME account that orders the certificate.";
$text['description-certificate_acme_account']['en-gb'] = "Select the ACME account that orders the certificate.";

$text['description-certificate_dns_provider']['en-us'] = "Select the DNS provider that publishes the TXT records.";
$text['description-certificate_dns_provider']['en-gb'] = "Select the DNS provider that publishes the TXT records.";

$text['label-certificate_pem']['en-us'] = "Certificate";
$text['label-certificate_pem']['en-gb'] = "Certificate";

$text['label-certificate_private_key']['en-us'] = "Private Key";
$text['label-certificate_private_key']['en-gb'] = "Private Key";

$text['description-certificate_source']['en-us'] = "Select ACME to have the certificate ordered and renewed automatically, or Import to paste a certificate issued elsewhere, such as a STIR/SHAKEN certificate from an STI-CA. An imported certificate is not renewed; you are warned before it expires.";
$text['description-certificate_source']['en-gb'] = "Select ACME to have the certificate ordered and renewed automatically, or Import to paste a certificate issued elsewhere, such as a STIR/SHAKEN certificate from an STI-CA. An imported certificate is not renewed; you are warned before it expires.";

$text['description-certificate_pem']['en-us'] = "Paste the certificate in PEM format, followed by its intermediate certificates. The names, dates and issuer are read from it.";
$text['description-certificate_pem']['en-gb'] = "Paste the certificate in PEM format, followed by its intermediate certificates. The names, dates and issuer are read from it.";

$text['description-certificate_private_key']['en-us'] = "Paste the private key of the certificate in PEM format. It is stored encrypted and never shown again. Leave it blank to keep the saved key.";
$text['description-certificate_private_key']['en-gb'] = "Paste the private key of the certificate in PEM format. It is stored encrypted and never shown again. Leave it blank to keep the saved key.";

$text['message-certificate_pem_invalid']['en-us'] = "No certificate in PEM format was found.";
$text['message-certificate_pem_invalid']['en-gb'] = "No certificate in PEM format was found.";

$text['message-certificate_key_invalid']['en-us'] = "No private key in PEM format was found. An encrypted key has to be decrypted first.";
$text['message-certificate_key_invalid']['en-gb'] = "No private key in PEM format was found. An encrypted key has to be decrypted first.";

$text['message-certificate_key_mismatch']['en-us'] = "The private key does not belong to the certificate.";
$text['message-certificate_key_mismatch']['en-gb'] = "The private key does not belong to the certificate.";

$text['label-certificate_dns_records']['en-us'] = "DNS Records";
$text['label-certificate_dns_records']['en-gb'] = "DNS Records";

$text['description-certificate_dns_records']['en-us'] = "Add these TXT records at your DNS host. The order carries on once every DNS resolver sees them; a name with two values needs both records.";
$text['description-certificate_dns_records']['en-gb'] = "Add these TXT records at your DNS host. The order carries on once every DNS resolver sees them; a name with two values needs both records.";

$text['label-certificate_deployments']['en-us'] = "Deployments";
$text['label-certificate_deployments']['en-gb'] = "Deployments";

$text['label-deployment_target']['en-us'] = "Target";
$text['label-deployment_target']['en-gb'] = "Target";

$text['label-deployment_options']['en-us'] = "Options";
$text['label-deployment_options']['en-gb'] = "Options";

$text['description-certificate_deployments']['en-us'] = "Select where the certificate is used. It is written there again by the service whenever it is renewed or imported again. Web UI hands the certificate to the root helper of the app, which reloads nginx and goes back to the previous certificate if nginx does not accept it. FreeSWITCH TLS writes the files of the TLS directory; the SIP profiles using TLS read them when they restart.";
$text['description-certificate_deployments']['en-gb'] = "Select where the certificate is used. It is written there again by the service whenever it is renewed or imported again. Web UI hands the certificate to the root helper of the app, which reloads nginx and goes back to the previous certificate if nginx does not accept it. FreeSWITCH TLS writes the files of the TLS directory; the SIP profiles using TLS read them when they restart.";

$text['option-deployment_target_freeswitch']['en-us'] = "FreeSWITCH TLS";
$text['option-deployment_target_freeswitch']['en-gb'] = "FreeSWITCH TLS";

$text['option-deployment_status_pending']['en-us'] = "Waiting to be deployed";
$text['option-deployment_status_pending']['en-gb'] = "Waiting to be deployed";

$text['option-deployment_status_deployed']['en-us'] = "Deployed";
$text['option-deployment_status_deployed']['en-gb'] = "Deployed";

$text['option-deployment_status_restart']['en-us'] = "Restart needed";
$text['option-deployment_status_restart']['en-gb'] = "Restart needed";

$text['option-deployment_status_failed']['en-us'] = "Failed";
$text['option-deployment_status_failed']['en-gb'] = "Failed";

$text['option-deployment_target_nginx']['en-us'] = "Web UI (nginx)";
$text['option-deployment_target_nginx']['en-gb'] = "Web UI (nginx)";

$text['option-deployment_status_waiting']['en-us'] = "Waiting for nginx";
$text['option-deployment_status_waiting']['en-gb'] = "Waiting for nginx";

?>
