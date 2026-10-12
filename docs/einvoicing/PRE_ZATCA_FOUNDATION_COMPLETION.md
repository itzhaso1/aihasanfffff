# Pre-ZATCA foundation completion

Technical foundation that can be finished before real ZATCA sandbox access. This document does not claim compliance.

`REAL ZATCA API IS NOT CONNECTED`  
`REAL PRODUCTION SIGNING IS NOT ENABLED`  
`NO FAKE ZATCA COMPLIANCE IS CLAIMED`  
`PHASE 2 ISSUE SAFETY REMAINS UNCHANGED`

## 1. Completed now

- Product decisions O3, O8, and O10 remain locked from Phase 3J-1.
- SecretStore and PrivateKeyStore stay separate. Production bindings are fail-closed.
- EGS onboarding stays one row per EGS and environment. CSR generation refuses a second CSR.
- Read model `PreZatcaFoundationReadinessService` plus `finance:e-invoicing-readiness`.
- Finance read endpoint `GET /api/finance/v1/e-invoicing/foundation`.
- Flutter read-only screen `/settings/zatca-foundation`.
- Real ZATCA HTTP binding in production is `UnavailableRealZatcaHttpTransport`.
- Real onboarding methods on `EgsZatcaRealAccessGate` throw `RealZatcaAccessRequiredException`.
- Internal transport categories only. No official ZATCA error codes.
- Certificate PEM and CSR PEM are hidden from model serialization.
- Certificate replacement must match workspace, EGS, and environment.

## 2. Product decisions

| ID | Decision |
| --- | --- |
| O3 | `ONE EGS SEQUENCE OWNER; ENVIRONMENT SEPARATION DEFERRED` |
| O8 | `DEFERRED` — `production_active` is not an issue-gate requirement |
| O10 | `API DEFAULT OFF` — no certificate PEM, secret, or private key by default |

## 3. Secret architecture

`SecretStore`: `put`, `get`, `exists`, `health`, `destroy`. Database stores `secret_ref` and `secret_store_driver` only. `DevMemorySecretStore` is for local and test. Production resolves `FailClosedProductionSecretStore`.

## 4. Private key architecture

`PrivateKeyStore`: `store`, `sign`, `health`, `destroy`. Database stores `store_key` (hidden from serialization) and lifecycle metadata. Production resolves `FailClosedProductionPrivateKeyStore`. Future KMS, HSM, Vault, or external stores implement the same interface. No vendor provider is implemented.

## 5. EGS onboarding architecture

Existing status plus OTP phase maps to:

`unconfigured` → `csr_generated` → `otp_requested` → `otp_received` → `onboarding_submitted` → `compliance_active` → `production_active`

`compliance_active` and `production_active` are not set by the fake adapter. Real steps live on `EgsZatcaRealAccessGate` and are blocked.

## 6. Certificate lifecycle

Activate, deactivate, expire, revoke, and supersede already exist. Supersede now also requires the same environment. ZATCA renewal and revocation protocol: `BLOCKED — OFFICIAL ZATCA SPECIFICATION REQUIRED`.

## 7. ZATCA HTTP adapter

`ZatcaHttpTransport` → `FakeZatcaHttpTransport` outside production, `UnavailableRealZatcaHttpTransport` in production. The unavailable adapter has no URL, path, or auth schema. `BLOCKED — REAL ZATCA ACCESS REQUIRED`.

## 8. Readiness dashboard

Workspace report uses `ready`, `not_ready`, `blocked`, `not_connected`, `not_applicable`. `compliance_claim`, `zatca_connected`, and `production_active` are false. Invoice-scoped checks (seller, buyer, tax, XML) stay `not_applicable` here and remain on the Phase 2 document gate.

## 9. API

`GET /api/finance/v1/e-invoicing/foundation` requires `finance.settings`. Payload includes EGS id/uuid/status, CSR fingerprint and timestamps, certificate metadata, credential kind/environment/status, and identity environment/purpose/status. It does not include PEM, `secret_ref`, or private keys.

## 10. Flutter

`ZatcaFoundationScreen` renders backend statuses as Ready, Missing, Blocked, Not Connected, or Pending. It does not store secrets and does not treat API reachability as a ZATCA connection. No onboarding mutation is added.

## 11. Tests

`tests/Feature/Feature/Finance/PreZatcaFoundationCompletionTest.php` covers readiness flags, CSR idempotency, blocked real operations, fake transport classification, API redaction, and the Artisan command.

## 12. Security audit

Credential `secret_ref` and private-key `store_key` stay hidden. Certificate `public_certificate` and onboarding `csr_pem` are hidden from serialization. ZATCA audit logs use `ZatcaApiRequest::auditContext()` and do not include OTP or secret values. QR cryptographic tag port does not reference `PrivateKeyStore` or `SecretStore`.

## 13. Multi-tenant audit

Onboarding, credentials, certificates, key references, and active identity resolution already require workspace, EGS, and environment alignment from Phases 3C–3F. Certificate supersede now rejects an environment mismatch.

## 14. Idempotency

`EgsOnboardingService::ensure` returns the existing row. CSR generation rejects an existing CSR or key reference. Credential activation supersedes another active row of the same kind and environment instead of leaving two active rows.

## 15. Failure recovery

Fake transport scenarios remain validation, authentication, timeout, and malformed response. Production transport and real OTP/CSID/activation fail closed. Failures do not mark production onboarding active.

## 16. Still blocked

- Official ZATCA specification: XAdES profile, QR tags 7–9 semantics, certificate renewal/revocation protocol, signing input.
- Real ZATCA access: OTP, CSID, compliance/production activation, clearance, reporting, FATOORA.
- Infrastructure: KMS/HSM/Vault providers.
- Internal product decisions already closed for this phase: O3, O8, O10. Further production-issue policy remains O8 deferred.

## 17. Next steps once ZATCA access arrives

1. Configure the real HTTP adapter behind `ZatcaHttpTransport` using verified contracts only.
2. Replace `EgsZatcaRealAccessGate` bodies with the verified OTP flow.
3. Store CSID material only as opaque secret references.
4. Import the real certificate through `CertificateRegistry`.
5. Bind production identity only through the authorized activation service.
6. Apply the approved cryptographic profile.
7. Replace `FailClosedXadesSigner` only after that profile.
8. Fill QR tag 7–9 providers from the same profile.
9. Run sandbox validation against the real adapter.
10. Activate production only after that validation.
11. Add clearance and reporting with verified endpoints.
12. Complete the external ZATCA audit. Do not treat this foundation report as that audit.
