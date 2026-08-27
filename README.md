# VeriFactu for Dolibarr — Check 4 Cyber distribution

Experimental Dolibarr module for evaluating the Spanish invoicing-system requirements (RRSIF / VERI*FACTU).

This fork is maintained by **Check 4 Cyber SARL** from the original work by **104 CUBES S.L. / Luis Garcia**. Original copyright and licence notices are preserved. See `LICENSE`, `COPYING` and individual file headers; the repository must not be represented as wholly authored by Check 4 Cyber.

## Status

**Experimental — not certified for production use.** The repository does not currently include a Check 4 Cyber declaration of responsibility. A disclaimer is not a substitute for the declaration required for a production SIF.

Production transmission is disabled by default and requires an explicit acknowledgement in module settings. This guard prevents accidental transmission; it does not prove regulatory conformity.

## Compatibility policy

| Dolibarr | PHP | Status |
| --- | --- | --- |
| 19.x | 7.4–8.2 | Targeted |
| 20.x | 7.4–8.3 | Targeted |
| 21.x | 8.1–8.3 | Targeted |
| 22.x | 8.1–8.4 | Targeted |

“Targeted” means accepted by the module descriptor. It becomes “validated” only after automated checks and a complete runtime test on that combination. Older and future Dolibarr versions are not silently claimed as compatible.

## Safe evaluation flow

1. Clone a non-production Dolibarr instance and database.
2. Install this repository as `htdocs/custom/verifactu104`.
3. Keep automatic sending disabled.
4. Configure the legal identity and numbering of the test issuer.
5. Exercise the cases in [`docs/TESTING.md`](docs/TESTING.md).
6. Validate generated XML against the current AEAT schemas and test service.
7. Reconcile Dolibarr invoices, generated records and AEAT acknowledgements.
8. Do not enable production until the exact release and installation are reviewed and covered by a declaration of responsibility.

## Security

Never commit certificates, private keys, P12 passwords, taxpayer data or AEAT acknowledgements. See [`SECURITY.md`](SECURITY.md).

## Scope

The module is intended for Spanish taxpayers in scope of RRSIF. Check 4 Cyber SARL being established in Luxembourg does not change the Spanish obligations of installations used by Spanish taxpayers.
