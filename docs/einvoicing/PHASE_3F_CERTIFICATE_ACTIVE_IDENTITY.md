# Phase 3F — Certificate lifecycle & active identity

**LOCAL CERTIFICATE ACTIVATION IS NOT ZATCA ACCEPTANCE.**

No real ZATCA certificate issuance. No production signing. No invoice/issue gate changes.

## 1. Existing certificate architecture audit

| Item | Finding |
| --- | --- |
| Table | `e_invoice_certificates` (Phase 9): public X.509 metadata, `test_fixture`, immutable update rule |
| Columns (pre-3F) | `workspace_id`, `egs_unit_id`, serial/subject/issuer/validity, fingerprint, `public_certificate`, algorithms, `status=imported` |
| Import | `CertificateRegistry::importPublicCertificate` + `X509CertificateParser` — PEM only, rejects private keys |
| Consumers | `EInvoiceCryptographicStampService` (by certificate id), Phase 9 tests |
| Gaps (Phase 3B) | No `environment`, `kind`, lifecycle, credential FK, active identity pointer |

**Phase 3F** extends the table additively and adds `egs_active_identities`.

## 2. Certificate lifecycle

Enum `EInvoiceCertificateLifecycleStatus`:

`pending_activation` → `active` → `inactive` / `expired` / `revoked` / `superseded`

Public material remains immutable (`EInvoiceCertificate::IMMUTABLE_ATTRIBUTES`). Only lifecycle fields (`lifecycle_status`, `superseded_by_id`, `egs_credential_id`) may change.

Service: `EInvoiceCertificateLifecycleService`.

## 3. EgsCredential binding

`EgsCredentialCertificateBindingService` enforces same workspace, EGS, environment, and kind. Updates bidirectional FKs (`egs_credentials.e_invoice_certificate_id`, `e_invoice_certificates.egs_credential_id`).

## 4. EgsActiveIdentity

Table `egs_active_identities` — authoritative pointer for future signing/API auth:

- `environment`, `purpose` (`compliance_api`, `production_signing`)
- FKs: credential, certificate, private key reference
- `status`: `active` / `inactive`

At most one **active** row per `(egs_unit_id, environment, purpose)` — enforced transactionally in `EgsActiveIdentityService`.

## 5. ActiveIdentityResolver

Input: workspace, EGS, environment, purpose → active `EgsActiveIdentity`.

Fail-closed checks: scope alignment, active credential, active certificate within validity, usable private key reference, credential/key consistency.

Does **not** read `finance_settings.zatca_certificate_serial` or `zatca_integration_mode` as identity sources.

## 6. Environment isolation

Certificates and identities carry `EInvoicingEnvironment`. Sandbox credentials/certificates cannot bind or resolve for production.

## 7. Workspace / EGS isolation

All services use `EgsUnitResolver` and scoped FK checks consistent with Phases 3C–3E.

## 8. PrivateKeyReference relationship

Activation and resolution call `PrivateKeyReferenceResolver` — no PEM exposure, no direct `DevMemoryPrivateKeyStore` usage from domain code.

## 9. SecretReference relationship

Identity resolution uses credential metadata only; `secret_ref` is never loaded or logged.

## 10. Test-only activation

`EgsActiveIdentityService::activateSandboxTestIdentity`:

- Sandbox only
- `compliance_api` purpose (production signing purpose blocked)
- Requires `test_fixture=true` certificate
- Documented as **LOCAL/TEST ONLY**

## 11. Production activation block

Production environment and `production_signing` purpose throw `production_activation_blocked_in_3f` / `production_signing_blocked_in_3f`.

## 12. Legacy-field exclusion

Legacy finance ZATCA settings may exist for UI; resolution requires `EgsActiveIdentity` rows.

## 13. ZATCA assumptions NOT made

**BLOCKED — OFFICIAL ZATCA SPECIFICATION REQUIRED** for: certificate profile/extensions, CSID certificate semantics, subject requirements, renewal/revocation protocols, production activation behavior.

## 14. Phase 3G dependencies

- Real ZATCA certificate import/issuance adapter
- Production identity activation (approved workflow)
- Signer integration to consume `ActiveIdentityResolver`
- Optional scheduled expiry/rotation jobs
