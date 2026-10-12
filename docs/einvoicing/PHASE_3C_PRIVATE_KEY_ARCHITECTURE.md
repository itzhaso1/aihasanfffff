# Phase 3C — Private Key Architecture & Secure Key Store

**Status:** Implemented  
**Date:** 24 September 2026  
**Prerequisite:** Phase 3B Domain & Security Architecture (approved)

---

## 1. Scope

Phase 3C adds:

- `egs_private_key_references` table (opaque handles only)
- `PrivateKeyReference` workspace-scoped model
- `PrivateKeyStore` interface + `PrivateKeyStoreException`
- `DevMemoryPrivateKeyStore` (local/test only, fail-closed in production)
- `PrivateKeyReferenceService`, `PrivateKeyReferenceResolver`, `PrivateKeyStoreEnvironmentGuard`

Phase 3C does **not** add CSR, OTP, CSID, ZATCA HTTP, production key generation, production signer wiring, API onboarding, or Flutter UI.

```text
Production private-key storage is NOT implemented.
Production key generation is NOT implemented.
Production signing is NOT implemented.
ZATCA HTTP is NOT implemented.
CSR generation is NOT implemented.
```

---

## 2. Approved Phase 3B decisions (honored)

| Decision | 3C behavior |
|----------|-------------|
| No PEM in application DB | Migration + runtime guard on stamp service table list |
| Same EGS for sandbox/production | `environment` column on references |
| ICV/PIH unchanged | No changes to `EgsUnit` counters |
| Issue gate unchanged | No `InvoiceIssueService` changes |
| Sandbox default | Enum supports `sandbox` / `production` on references |
| Phase 9A open | No curve/signing policy encoded |

---

## 3. Domain model

```text
Workspace
  └── EgsUnit
        └── PrivateKeyReference (N)
              store_driver + store_key → PrivateKeyStore backend
```

---

## 4. PrivateKeyReference

Table: `egs_private_key_references`

| Column | Purpose |
|--------|---------|
| `workspace_id`, `egs_unit_id` | Tenant + EGS isolation |
| `environment` | `sandbox` \| `production` |
| `store_driver` | e.g. `dev_memory` |
| `store_key` | Opaque handle (hidden from JSON serialization) |
| `public_key_fingerprint` | Metadata only |
| `status` | `active` \| `revoked` \| `destroyed` |
| `revoked_at`, `destroyed_at` | Lifecycle audit |

**Forbidden columns:** `private_key_pem`, `private_key`, `secret`, `passphrase` (none added).

---

## 5. PrivateKeyStore interface

`App\EInvoicing\Security\PrivateKeyStore`:

- `store(reference, pem, fingerprint)` — dev driver only in 3C
- `sign(reference, digest)` — dev/test signing
- `health(reference)`
- `destroy(reference)`

Legacy `PrivateKeyMaterial` retained as documentation marker; no production use.

---

## 6. Dev-only implementation

`App\Services\EInvoicing\Security\DevMemoryPrivateKeyStore`:

- PEM held in **process memory** only
- `PrivateKeyStoreEnvironmentGuard` throws in `production` environment
- Bound as singleton implementation of `PrivateKeyStore` in `AppServiceProvider`
- **Not** usable by `DeferredCryptographicStampSigner` (unchanged)

---

## 7. Database schema

Migration: `2026_09_24_130000_create_egs_private_key_references_table.php`

- FKs: `workspace_id`, `egs_unit_id`
- Unique: `store_key`
- Indexes: `(workspace_id, egs_unit_id, environment)`, `(…, status)`

Additive only; no changes to existing e-invoice tables beyond stamp service guard list.

---

## 8. Security boundaries

```text
(Future) Stamp Service → ActiveIdentityResolver → PrivateKeyReferenceResolver → PrivateKeyStore::sign
```

Controllers and Finance API **do not** call `PrivateKeyStore` in 3C.

`EInvoiceCryptographicStampService::assertNoPrivateKeyColumns()` includes `egs_private_key_references`.

---

## 9. Lifecycle

```text
active → revoked
active → destroyed
revoked → (terminal for signing; no service method to reactivate)
destroyed → terminal
```

---

## 10. Workspace / environment isolation

`PrivateKeyReferenceResolver` enforces:

- workspace match
- optional EGS match
- environment match
- `active` status

Covered by `Phase3CPrivateKeyArchitectureTest`.

---

## 11. Production restrictions

- `DevMemoryPrivateKeyStore` + `PrivateKeyReferenceService::createActiveDevReference` fail in production environment
- `CryptographicStampSigner` binding unchanged (`DeferredCryptographicStampSigner`)

---

## 12. Test coverage

`tests/Feature/Feature/Finance/Phase3CPrivateKeyArchitectureTest.php`:

- Migration forbidden columns
- CRUD lifecycle, isolation, dev sign, serialization redaction, production guard

Regression: run Phase 7/9/10 Finance e-invoice tests alongside 3C.

---

## 13. Open decisions remaining

- Production `PrivateKeyStore` driver (KMS/HSM) — **O12**
- Algorithm metadata on references — deferred
- Whether destroyed rows may be purged by retention job — product/ops

---

## 14. Phase 3D dependencies

Phase 3D (CSR generation) will:

- Generate key material via `PrivateKeyStore` (dev driver in non-prod)
- Create `PrivateKeyReference` rows with fingerprints from CSR flow
- Introduce `egs_onboarding` state machine (not in 3C)

---

```text
NO production ZATCA onboarding in Phase 3C
```
