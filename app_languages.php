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

?>
