# Phase 3J-1 — Product decisions + secret/KMS architecture foundation

**REAL ZATCA API IS NOT CONNECTED**  
**REAL PRODUCTION SIGNING IS NOT ENABLED**  
**PHASE 2 ISSUE SAFETY REMAINS UNCHANGED**

---

## Product decisions (locked for this phase)

### O3 — ICV/PIH counters

**Decision:** `ONE EGS SEQUENCE OWNER; ENVIRONMENT SEPARATION DEFERRED`

**Evidence:**

- `egs_units.next_icv` and `egs_units.last_invoice_hash` drive `IcvAllocator`.
- No `environment` column on `egs_units` or `e_invoice_security_records`.
- Sandbox/production isolation for credentials/onboarding does **not** split ICV/PIH sequences.
- **No migration** for counter separation in 3J-1.

### O8 — Production active required for issue?

**Decision:** `DEFERRED`

**Evidence:**

- `ZatcaRequiredIssueGate` requires seller/buyer readiness, XML/XSD/schematron path, and mandatory ICV/PIH/hash — **not** `production_active`, not `ActiveIdentityResolver`.
- **No runtime change** in 3J-1.

### O10 — Certificate PEM in API

**Decision:** `API DEFAULT OFF`

**Evidence:**

- No Finance API Resource/Controller references to `public_certificate` or `secret_ref` (audit 3J-1).
- `EgsCredential` hides `secret_ref` from serialization.
- Future certificate endpoints should expose metadata only (fingerprint, serial, subject, issuer, validity, environment, lifecycle) unless an explicit audited export flag is added later.

---

## SecretStore boundary

| Concern | Implementation |
|--------|----------------|
| Interface | `SecretStore`: `put`, `get`, `exists`, `health`, `destroy` |
| Dev driver | `DevMemorySecretStore` — process memory, tests/local only |
| Production binding | `FailClosedProductionSecretStore` — no fallback |
| DB | `egs_credentials.secret_ref`, `secret_store_driver` — opaque only |
| Internal resolve | `SecretReferenceService::resolveForInternalUse()` — not for HTTP |

**Not stored in DB:** OAuth/CSID raw secrets, OTP, passwords, private keys.

---

## PrivateKeyStore boundary

| Concern | Implementation |
|--------|----------------|
| Interface | `PrivateKeyStore`: `store`, `sign`, `health`, `destroy` |
| Dev driver | `DevMemoryPrivateKeyStore` |
| Production binding | `FailClosedProductionPrivateKeyStore` |
| DB | `egs_private_key_references.store_key` + driver — no PEM |

**Separation:** SecretStore ≠ PrivateKeyStore (OAuth/API secrets vs EGS signing keys).

---

## Production guards

| Component | Non-production (`local`, `testing`) | `production` app env |
|-----------|-------------------------------------|----------------------|
| `SecretStore` | `DevMemorySecretStore` | `FailClosedProductionSecretStore` |
| `PrivateKeyStore` | `DevMemoryPrivateKeyStore` | `FailClosedProductionPrivateKeyStore` |
| Dev memory `put`/`get` | Allowed (with guard on put) | `ProductionSecretStoreUnavailableException` / guard |
| `CryptographicStampSigner` | `DeferredCryptographicStampSigner` (unchanged) | Same fail-closed default |
| `ZatcaHttpTransport` | `FakeZatcaHttpTransport` (no real HTTP) | Same — not production ZATCA |

---

## Future KMS/HSM insertion

1. Add `SecretStoreDriver` / `PrivateKeyStoreDriver` enum value (e.g. `aws_kms`, `vault`, `hsm`).
2. Implement `SecretStore` / `PrivateKeyStore` adapters behind those drivers.
3. Change `AppServiceProvider` production bindings to resolve configured driver — **only when infrastructure exists**.
4. **BLOCKED — INFRASTRUCTURE / DEPENDENCY REQUIRED** until then.

---

## Intentionally NOT implemented

- Real ZATCA API, OTP, CSID provisioning
- Production certificate activation, production signing, XAdES, QR 7–9
- Real KMS/HSM/Vault providers
- ICV/PIH environment split (O3 deferred)
- Issue gate requiring `production_active` (O8 deferred)
