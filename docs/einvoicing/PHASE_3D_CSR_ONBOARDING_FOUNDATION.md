# Phase 3D — CSR Generation & Onboarding Foundation

**Status:** Implemented  
**Date:** 24 September 2026  
**Prerequisites:** Phase 3B (design), Phase 3C (PrivateKeyStore)

---

## 1. Scope

- `egs_onboarding` domain + state machine (CSR-related states only)
- Dev/test CSR generation via OpenSSL
- Integration with Phase 3C `PrivateKeyStore` / `PrivateKeyReference`
- Workspace + EGS + environment isolation
- No ZATCA HTTP, OTP, CSID, certificates, signing, or public API

```text
Production private-key storage is NOT implemented.
Production key generation is NOT implemented.
Production signing is NOT implemented.
ZATCA HTTP is NOT implemented.
CSR generation for production e-invoicing environment is NOT implemented in 3D.
```

---

## 2. Approved Phase 3B / 3C decisions (honored)

| Decision | 3D behavior |
|----------|-------------|
| Same EGS, sandbox + production onboarding rows | Unique `(egs_unit_id, environment)` |
| No ICV/PIH changes | Unchanged |
| No issue gate changes | Unchanged |
| PrivateKeyStore boundary | CSR service never returns PEM |
| Sandbox default for new flows | Sandbox CSR allowed in dev/test app env |
| Phase 9A open | Dev curve `secp256k1` **TEST ONLY** in `DevTestCsrGenerator` |

---

## 3. Domain model

**Table:** `egs_onboarding`

| Column | Purpose |
|--------|---------|
| `workspace_id`, `egs_unit_id`, `environment` | Isolation |
| `status` | State machine |
| `private_key_reference_id` | FK to Phase 3C reference |
| `csr_pem` | **Public** CSR artifact (O5) |
| `csr_fingerprint_sha256` | CSR metadata |
| `csr_generated_at` | Timestamp |
| `failure_code`, `failure_message` | Failed state |

**Unique:** `(egs_unit_id, environment)`

---

## 4. O5 — CSR storage decision

**Decision:** **A — Public CSR PEM in `egs_onboarding.csr_pem`**

**Evidence:**

- `e_invoice_certificates.public_certificate` already stores public X.509 PEM in DB
- No generic “document artifact” service exists for one-off onboarding blobs
- Phase 3B proposed onboarding row holding public CSR

**Not stored:** private key, passphrase, secrets, OTP, CSID.

---

## 5. Authorization decision

| Item | Value |
|------|--------|
| Mechanism | Spatie permissions via `User::can()` / Finance API `clientActor` |
| Permission for future onboarding mutations | **`finance.settings`** |
| Evidence | `SettingsController` (company/ZATCA settings), `FoundationSeeder`, `ManagerFinancePrivilegeTest`, `FinanceApiFeatureGateTest` |
| Dedicated `zatca.*` permission | **Does not exist** — not invented in 3D |
| Phase 3D | Domain services only; `EgsOnboardingAuthorization::PERMISSION` documents future API gate |

---

## 6. Onboarding state machine (3D subset)

**Implemented transitions:**

```text
unconfigured → csr_generated   (generate CSR)
unconfigured → failed          (error)
failed → csr_generated         (retry after failed, if no CSR/key present)
```

**Enum includes future states** (`compliance_pending`, `production_active`, …) **without** transition methods.

**Blocked in 3D:**

- `compliance_active`, `production_pending`, `production_active` — no fake activation

**Regeneration:** Second CSR on `csr_generated` → exception (`csr_already_present`).

---

## 7. CSR generation flow

```text
EgsCsrGenerationService::generateForEgs
  → assert dev app environment (PrivateKeyStoreEnvironmentGuard)
  → reject EInvoicingEnvironment::Production (3D)
  → EgsOnboardingService::ensure
  → DevTestCsrGenerator (OpenSSL, in-process PEM — not returned)
  → PrivateKeyReferenceService::createActiveDevReference
  → PrivateKeyReferenceResolver (environment check)
  → persist csr_pem + status csr_generated (transaction)
```

**Subject DN:** `CsrSubjectData` + `DevTestCsrGenerator` — placeholder DN from EGS name + `FinanceSetting` company/country; **not** documented ZATCA Table 1 production profile.

**Dev key source:** Tries OpenSSL EC (`secp256k1`) then RSA; if the host OpenSSL cannot generate keys (observed on some Windows CI/dev builds), falls back to **`tests/Fixtures/EInvoicing/test-only-egs-*.key.pem`** in `local`/`testing` only — **not** production.

**Dev CSR artifact:** Uses OpenSSL `openssl_csr_new` when available; otherwise optional `test-only-egs-*.csr.pem` fixtures if present, else a **dev placeholder** PEM (marked `BEGIN CERTIFICATE REQUEST`, not a valid PKCS#10 for ZATCA) — **not** production.

---

## 8. PrivateKeyStore integration

- No changes to `PrivateKeyStore` interface
- Business code uses `PrivateKeyReferenceService` only (not `DevMemoryPrivateKeyStore` directly)
- Production **app** env: dev store blocked (Phase 3C)
- Production **e-invoicing** env: CSR generation blocked in 3D (`production_csr_not_implemented`)

---

## 9. Deliberately NOT implemented

OTP, CSID, ZATCA HTTP, certificate import/activation, production KMS/HSM, XAdES, QR 7–9, FATOORA, Flutter UI, public CSR download API, invoice issue wiring, rotation workflow.

---

## 10. Phase 3E dependencies

- ZATCA HTTP client + OTP
- `EgsCredential` + secret vault
- Transitions `csr_generated` → `compliance_pending` → …
- Production key store driver
- Optional: verified ZATCA CSR subject/extensions in `CsrSubjectData`

---

## 11. Tests

`tests/Feature/Feature/Finance/Phase3DCsrOnboardingTest.php`

Regression bundle: Phase 3C, 7, 9, 2D (see implementation report).
