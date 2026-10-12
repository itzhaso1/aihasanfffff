# Phase 3E — ZATCA integration foundation

**Status:** Architecture and test harness only.

**REAL ZATCA API IS NOT CONNECTED.** No outbound HTTP to ZATCA. No guessed URLs, headers, OTP contracts, or CSID schemas.

## 1. Architecture

```
EgsZatcaIntegrationService
    ├── EgsZatcaOtpWorkflowService (metadata only; OTP never stored)
    ├── EgsCredentialService (lifecycle)
    ├── SecretReferenceService → SecretStore (opaque refs)
    └── ZatcaHttpTransport → FakeZatcaHttpTransport (Phase 3E default)
```

Environment routing is mandatory on every operation: `workspace_id`, `egs_unit_id`, `environment`. Credentials are resolved with fail-closed workspace / EGS / environment checks. Legacy `finance_settings.zatca_*` fields are not used for identity or routing.

## 2. EgsCredential

Table: `egs_credentials`.

Stores metadata and opaque `secret_ref` + `secret_store_driver`. Never stores raw CSID secret, OTP, tokens, passwords, or private keys. Optional FK to `e_invoice_certificates` (Phase 3F). Links to `private_key_reference_id` from Phase 3D onboarding where applicable.

Kinds: `compliance`, `production` (production activation blocked in 3E).

Statuses: `pending`, `active`, `expired`, `revoked`, `superseded`.

## 3. SecretReference / SecretStore

- Interface: `App\EInvoicing\Security\Secrets\SecretStore`
- Dev driver: `DevMemorySecretStore` (process memory, tests/local only)
- `SecretReferenceService` writes secrets by opaque UUID ref; DB rows hold ref + driver only.

Production app environment: dev memory driver fails closed (same guard pattern as Phase 3C private key store).

## 4. ZATCA HTTP abstraction

- `ZatcaHttpTransport::dispatch(ZatcaApiRequest): ZatcaApiResponse`
- `ZatcaOperation` enum labels are **internal** — not verified ZATCA endpoint names.
- `ZatcaApiRequest::auditContext()` is safe for logs (no secrets).

## 5. Fake / mock adapter

`FakeZatcaHttpTransport`:

- Never performs HTTP
- Payloads marked `fake: true`, `not_a_real_zatca_csid: true`
- Scenarios: success, validation failure, authentication failure, timeout, malformed response, expired credential

Bound as the default `ZatcaHttpTransport` in `AppServiceProvider`.

## 6. OTP foundation

- `EgsOnboarding.otp_phase`: `none` → `otp_requested` → `otp_received` → `onboarding_submitted` | `failed`
- `TransientOtpValue`: in-memory only; cannot serialize; `__toString()` redacted
- OTP is never written to DB, logs, or exceptions

## 7. Credential lifecycle

Simulated sandbox **compliance** issuance may move a row `pending` → `active` and supersede prior active rows of the same EGS + environment + kind.

**Production** credentials cannot be activated in Phase 3E (`production_activation_blocked_in_3e`).

Onboarding status is **not** promoted to `compliance_active` / `production_active` in 3E.

## 8. Environment / EGS / workspace isolation

`EgsCredentialResolver` enforces workspace, EGS, and environment match before transport use.

## 9. Security boundaries

| Allowed in DB/logs | Forbidden |
| --- | --- |
| workspace/EGS/environment, operation, correlation ID, secret_ref (opaque) | OTP, raw CSID/OAuth secret, bearer token, private key PEM |

## 10. BLOCKED — official ZATCA specification required

Until verified documentation and credentials exist in the project:

- Exact base URLs
- Exact endpoints and HTTP methods
- Request/response schemas
- OTP endpoint, headers, payload
- CSID issuance endpoint and response fields
- Authentication headers / OAuth/token behavior
- Certificate issuance from ZATCA
- Production activation workflow
- Exact error codes and retry policy

Do not implement a real HTTP adapter against guessed values.

## 11. Migration notes

Additive:

- `2026_09_24_150000_create_egs_credentials_table.php`
- `2026_09_24_151000_add_egs_onboarding_zatca_foundation_columns.php` (`external_request_id`, `otp_phase`)

## 12. Phase 3F dependencies

- Real `EInvoiceCertificate` binding to credentials
- Verified ZATCA API adapter implementing `ZatcaHttpTransport`
- Production secret backend (KMS/HSM/vault) — not chosen in 3E
- Onboarding promotion to `compliance_active` / production activation (approved workflow)

## 13. Explicitly NOT implemented

- Real ZATCA HTTP, clearance, reporting, FATOORA, XAdES, QR 7–9
- Invoice issue / ICV-PIH / issue gate changes
- Flutter onboarding UI
- Public `/zatca/*` API routes (unless added in a later approved phase)
