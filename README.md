# fusionpbx-app-cert_manager

Manage the certificates of a FusionPBX server from its web UI: the TLS certificate of the web UI,
the TLS certificate of FreeSWITCH, and the STIR/SHAKEN signing certificate.

Certificates are ordered and renewed from any ACME certificate authority, such as Let's Encrypt,
for single names and wildcards. Wildcards are validated over DNS through pluggable DNS providers.

## License

Mozilla Public License 1.1, the same license as FusionPBX. See [LICENSE](LICENSE).
