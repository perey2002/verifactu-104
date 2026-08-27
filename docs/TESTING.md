# Validation plan

Record the Dolibarr version, PHP version, database engine, module commit/tag, entity ID, timezone and AEAT environment for every run. Sanitise evidence before attaching it to public issues.

## Installation and isolation

- Fresh install and upgrade from the previous module version.
- Enable/disable without affecting unrelated invoices or modules.
- Multi-company entities keep constants, documents, certificates and chains isolated.
- Unsupported Dolibarr/PHP versions are rejected or clearly warned.

## Invoice lifecycle

- First invoice and subsequent chained invoices; multiple independent series.
- Standard, simplified and rectifying invoices where supported.
- Cancellation and correction/subsanación.
- Multiple VAT rates, exemptions, negative lines and rounding.
- EU/non-EU identifiers and customers without a Spanish NIF.
- Concurrent validation of two invoices in the same series.

## Transport and recovery

- Valid, expired, revoked and wrong-taxpayer certificates.
- DNS failure, timeout, TLS failure, HTTP error and malformed SOAP.
- Accepted, accepted-with-errors, rejected and retry responses.
- Retries are idempotent and cannot create duplicate records.
- Crash before/after AEAT acceptance and local-state recovery.

## Integrity and release gate

- XML validates against the current official XSD.
- Hash input/output match official examples and predecessor identity is correct.
- QR and invoice legend match the operating mode.
- XML, acknowledgement, event log and PDF remain readable and attributable.
- Tampering is detected or leaves an auditable event.

Production requires all applicable cases to pass, independent review, and a declaration of responsibility for the exact version.
