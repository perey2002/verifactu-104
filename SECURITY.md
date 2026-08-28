# Security policy

Only the latest tagged Check 4 Cyber release is eligible for fixes. The current `0.x` line is experimental and must not be treated as production-ready.

Do not open a public issue containing taxpayer data, invoices, certificates, private keys, passwords or AEAT responses. Use the private contact channel published at https://c4c.lu.

- Use a different certificate and private key for each legal entity.
- Keep certificate files outside the web root with least-privilege permissions.
- Never reuse test credentials or taxpayer identities in production.
- Back up invoice evidence encrypted and test restoration.

Automatic transmission and production mode are separate controls. Enabling either one does not certify the installation.
