# Phase 3B — Domain & Security Architecture (Audit + Design)

**Status:** Design only — no code, migrations, or dependencies changed  
**Date:** 24 September 2026  
**Prerequisite:** Phase 2 closed; Phase 3A CSID/CSR/OTP readiness audit complete  
**Scope:** HASEM Finance / E-Invoicing domain — workspace tenancy, EGS, environment, certificates, CSID metadata, onboarding, private-key references

---

## 1. Executive Summary

HASEM already has a **Phase 2–7 security chain** (EGS-scoped ICV/PIH/hash), a **Phase 9 public-certificate registry** (immutable X.509 metadata, no private keys), and a **deferred production signer**. There is **no** CSR, OTP, ZATCA HTTP, CSID secret store, environment model, or onboarding lifecycle in code.

Phase 3B proposes a **layered domain model** that **extends** (not replaces) existing tables where possible:

- **Workspace** remains the tenant boundary.
- **EGS unit** remains the ICV/PIH sequence owner; it gains **lifecycle and optional device metadata**, not CSID secrets.
- **Environment** (`sandbox` | `production`) is modeled at **credential + onboarding + active-identity** levels; certificates inherit environment from their onboarding/credential row.
- **Compliance vs Production identity** are separate **credential kinds** tied to one EGS + environment; each maps to an **`e_invoice_certificates` row** (public X.509) plus a **non-DB secret reference**.
- **Onboarding** is a new **mutable state machine** per `(workspace, egs_unit, environment)`; certificates and stamps stay **immutable** once issued/used.

Production behavior stays **fail-closed**: no silent fallback to `finance_settings.zatca_*` for signing or identity resolution.

**Verdict:** Safe to proceed to **Phase 3C+ implementation design reviews** after product approval of open decisions listed in §16.

---

## 2. Current Architecture Audit

### 2.1 Data layer

| Artifact | Current role | Phase | Production-safe today? | 3B action |
|----------|--------------|-------|------------------------|-----------|
| `egs_units` | ICV/PIH sequence; default unit per workspace (deterministic UUID) | 7 | Yes (no secrets) | **Extend** — metadata, optional labels; not replace |
| `e_invoice_security_records` | Immutable ICV, PIH, hash per document | 7 | Yes | **Keep** — add optional `environment` snapshot column later if needed for audit |
| `e_invoice_certificates` | Immutable public X.509 metadata; `status=imported`; `test_fixture` | 9 | Yes (no private key) | **Extend** — kind, environment, lifecycle status; keep immutability |
| `e_invoice_cryptographic_stamps` | Immutable test stamp artifacts | 9 | Yes (test-only path) | **Keep** — production `stamp_status` when signer exists |
| `e_invoice_documents` | Compliance lifecycle + validation columns (Phase 2D) | 2/10 | Yes | **Keep** — optional FK to credential used at stamp time (future) |
| `finance_settings` | `zatca_integration_mode`, `zatca_certificate_serial`, `zatca_last_synced_at` | Legacy / placeholder | Yes (unused for crypto) | **Deprecate for identity** — see §11 |

**Duplicate / legacy:** ZATCA fields on `finance_settings` are **not** wired to `CertificateRegistry` or EGS. No second certificate store found.

### 2.2 Services

| Service | Current behavior | Phase | Production-safe? | Extension vs replacement |
|---------|------------------|-------|------------------|---------------------------|
| `EgsUnitResolver` | Default or explicit EGS; workspace match; `active` check | 7 | Yes | **Extend** — resolve by policy + environment (new resolver) |
| `IcvAllocator` | Locks EGS; allocates ICV; updates `last_invoice_hash` | 7 | Yes | **Keep** — ICV sequence is environment-agnostic in ZATCA docs reviewed in-repo; **open decision** if sandbox must use separate ICV chain |
| `EInvoiceSecurityService` | XML → enrich → hash; persists security record | 7 | Yes | **Keep** — optional later: bind `egs_unit_id` to resolved active EGS |
| `CertificateRegistry` | Import public PEM; reject private keys; workspace+EGS checks | 9 | Yes | **Extend** — typed import from onboarding pipeline |
| `EInvoiceCryptographicStampService` | Stamp after Phase 7; rejects deferred signer; forbids private-key DB columns | 9 | Yes (fail-closed default) | **Extend** — use identity resolver + production signer (3G+) |
| `InvoiceIssueService` | Snapshot → XML → validation → security → QR (Tags 1–6); **no stamp** | 10 | Yes | **Extend** — optional stamp hook after policy (not in 3C) |
| `InvoiceService::issue` | Gate (2D) → snapshot → `prepareFromSnapshot` → pipeline assert | 2/10 | Yes | **Keep** — future gate may require active production identity |
| `EInvoiceArtifactService` | Readiness/compliance DTO for Web/API/Flutter; no secrets | 10 | Yes | **Extend** — surface onboarding status read models |
| `DeferredCryptographicStampSigner` | Default binding; throws on `sign()` | 9 | Yes (intentional block) | **Replace binding only** when production signer ready |
| `TestCryptographicStampSigner` | Test-only; PEM in memory | 9 | Yes (guarded) | **Keep** for tests |

### 2.3 API & controllers

| Surface | ZATCA / crypto related behavior | Phase | Notes |
|---------|----------------------------------|-------|-------|
| `POST /api/finance/v1/invoices/{id}/cryptographic-stamp` | Always **409** `production_crypto_unavailable` | 10 | Stub — not onboarding |
| `GET .../xml`, `GET .../qr` | Issued artifact read | 10 | No cert/secret leakage (contract tests) |
| Finance `SettingsController` | **Strips** `zatca_*` from update payload | — | Legacy fields read-only de facto |
| Web Finance invoices | `zatca_requirement`, artifact availability | 2/10 | No EGS admin |
| No routes | EGS CRUD, CSR, OTP, cert import | — | **Gap** — Phase 3J API |

**Policies / Resources:** No dedicated EGS or certificate policies found; Finance API uses workspace membership + permission strings (existing pattern).

### 2.4 Models & isolation

- `EgsUnit`, `EInvoiceCertificate`, `EInvoiceSecurityRecord`, `EInvoiceCryptographicStamp` extend `WorkspaceScopedModel` + `workspace_id` FKs.
- Cross-workspace EGS/certificate use rejected in `EgsUnitResolver`, `CertificateRegistry`, `EInvoiceSecurityService`, `EInvoiceCryptographicStampService` (tests in Phase 7/9).

---

## 3. Current Entities (as-is)

```text
Workspace (workspaces)
  └── finance_settings (1:1) — zatca_* legacy columns
  └── egs_units (1:N)
        └── e_invoice_security_records (1:N, via documents)
        └── e_invoice_certificates (1:N)
        └── e_invoice_cryptographic_stamps (1:N, via documents)
  └── e_invoice_documents (1:N)
        └── e_invoice_security_records (1:1)
        └── e_invoice_cryptographic_stamps (0:1)
```

**Not modeled today:** environment, CSID secret, CSR, onboarding state, active certificate pointer, private-key reference, compliance vs production kind.

---

## 4. Proposed Domain Model

Design principle: **secrets and onboarding state are mutable; certificates, security records, and stamps remain immutable.**

### 4.1 Entity list (proposed — not implemented)

| Entity | Purpose | Tenant scope | Mutable? |
|--------|---------|--------------|----------|
| `EgsUnit` (existing, extended) | Device/solution unit; ICV/PIH owner | `workspace_id` | Limited (name, status, metadata) |
| `EgsOnboarding` (new) | CSR/OTP/CSID workflow state per EGS + environment | `workspace_id` | Yes (state machine) |
| `EInvoiceCertificate` (existing, extended) | Public X.509 + kind + lifecycle | `workspace_id`, `egs_unit_id` | **Immutable** after insert (keep current rule) |
| `EgsCredential` (new) | CSID metadata + secret **reference** (not plaintext) | `workspace_id`, `egs_unit_id`, `environment` | Status mutable; secret ref rotated |
| `PrivateKeyReference` (new) | Opaque handle to key material in vault/HSM | `workspace_id`, `egs_unit_id`, `environment` | Revocable; no PEM in DB |
| `EgsActiveIdentity` (new) | Pointer: which credential + cert + key ref is **active** for signing | `workspace_id`, `egs_unit_id`, `environment`, `purpose` | Mutable (admin/rotation) |

**Relationship sketch (final design choice):**

```text
Workspace
  └── EgsUnit
        ├── EgsOnboarding (unique: egs_unit_id + environment)
        ├── EgsCredential (N: compliance + production over time; unique active per kind+env)
        │     ├── e_invoice_certificate_id (FK, public cert)
        │     └── private_key_reference_id (FK)
        ├── EgsActiveIdentity (unique: egs_unit_id + environment + purpose)
        │     └── → EgsCredential (production_signing | compliance_api)
        └── (existing) security_records, stamps
```

**Why not nest “Compliance Identity” and “Production Identity” only under EGS without `EgsCredential`?**  
ZATCA Portal describes **Compliance CSID** and **Production CSID** as distinct artifacts with OAuth secret material. Modeling them as **`EgsCredential` rows** with `kind` enum avoids overloading `e_invoice_certificates` with secrets and preserves immutable cert history.

### 4.2 Foreign keys & uniqueness (proposed)

| Constraint | Rationale |
|------------|-----------|
| `UNIQUE (workspace_id, egs_unit.uuid)` | Keep existing |
| `UNIQUE (egs_unit_id, environment)` on `egs_onboarding` | One onboarding flow per EGS per environment |
| `UNIQUE (workspace_id, fingerprint_sha256)` on certificates | Keep existing |
| `UNIQUE (egs_unit_id, environment, kind)` where `egs_credentials.status = active` | **Partial unique** (DB-specific) or enforced in service: at most one **active** credential per kind per environment |
| `UNIQUE (egs_unit_id, environment, purpose)` on `egs_active_identities` | One active resolution row per purpose |
| Indexes: `(workspace_id, egs_unit_id)`, `(workspace_id, environment)`, `(egs_unit_id, kind, status)` | List/filter in admin API |

### 4.3 Isolation boundaries

| Boundary | Rule |
|----------|------|
| Workspace | All queries scoped; FK `workspace_id` on every new table |
| EGS | Credentials, certs, onboarding, key refs must match `egs_unit.workspace_id` |
| Environment | Resolver never loads sandbox credential when `production` requested |
| Document issue | Security record keeps historical `egs_unit_id`; stamp uses **active identity at stamp time** (persist credential id on stamp row — future column) |

---

## 5. Environment Model

### 5.1 Recommended layering

| Layer | Carries `environment`? | Reason |
|-------|------------------------|--------|
| **EgsOnboarding** | **Yes** (part of key) | OTP/CSR/CSID flows differ per ZATCA environment |
| **EgsCredential** | **Yes** (part of key) | Prevents sandbox secret used for production signing |
| **PrivateKeyReference** | **Yes** | Keys must not be shared across environments |
| **EInvoiceCertificate** | **Yes** (column) | Certs are environment-specific artifacts |
| **EgsUnit** | **Optional default only** | Unit may exist in both worlds with **separate** onboarding rows — do **not** store single env on EGS alone |
| **ICV/PIH sequence (`egs_units`)** | **Open decision** | Current code: one sequence per EGS. Sandbox may need **separate EGS unit** or **separate counter** — see §16 |

### 5.2 Fail-closed rules

1. Request context includes `environment` (from workspace setting **or** explicit admin configuration — product decision).
2. `ActiveIdentityResolver` requires `environment` match on credential, cert, and key ref.
3. Application config (future `config/zatca.php`) base URL must match environment; **no** override from client without platform admin role.
4. **Forbidden:** Using production CSID secret when `APP_ENV=local` unless explicit dangerous flag (default off).

---

## 6. Certificate Model

### 6.1 Extension of `e_invoice_certificates`

Add conceptual columns (future migrations):

| Column | Purpose |
|--------|---------|
| `environment` | `sandbox` \| `production` |
| `kind` | `compliance` \| `production` \| `test_fixture` (keep bool or migrate) |
| `lifecycle_status` | See §6.2 |
| `superseded_by_id` | Nullable FK to newer cert |
| `credential_id` | Nullable FK to `egs_credentials` that imported this cert |

**Keep immutable:** `public_certificate`, fingerprint, serial, subject, issuer, validity, algorithms.

**Must not store:** private key, CSID OAuth secret, OTP, CSR private material.

### 6.2 Lifecycle statuses (proposed)

| Status | Meaning | Signing allowed? |
|--------|---------|------------------|
| `pending_activation` | Parsed/stored; not yet active | No |
| `active` | Selected for its kind+env | Yes (if credential + key valid) |
| `inactive` | Replaced but retained | No |
| `expired` | `not_after` passed | No |
| `revoked` | ZATCA/operator revoked | No |
| `superseded` | New cert issued | No (historical stamps keep FK) |

**Rotation:** Import new cert → new row → onboarding/credential update → flip `EgsActiveIdentity` → mark old cert `superseded` / `inactive`. Stamps and documents **keep** old `e_invoice_certificate_id`.

### 6.3 Active certificate selection

- **Not** inferred from latest `not_after` alone.
- **Source of truth:** `EgsActiveIdentity` → `EgsCredential` → `e_invoice_certificate_id`.
- **Enforcement:** Service-layer transaction when activating; partial unique index on active credentials.
- **Multiple certs per EGS:** Allowed historically; **at most one active** per `(egs_unit, environment, kind)`.

### 6.4 Direct EGS link

**Yes** — keep `egs_unit_id` FK (existing). Certificate always belongs to exactly one EGS unit.

---

## 7. Secret / Private Key Architecture

### 7.1 Abstraction (extend existing)

Keep namespace `App\EInvoicing\Security\PrivateKeyMaterial` but evolve to:

```text
PrivateKeyReference     — value object: opaque id, workspace_id, egs_unit_id, environment, algorithm (TBD), created_at, revoked_at
PrivateKeyStore         — interface: store(ref), sign(ref, digest), destroy(ref), health(ref)
PrivateKeyStoreException — fail-closed errors
```

**Implementations (future, not 3B):** `EncryptedFilePrivateKeyStore`, `EnvPrivateKeyStore` (dev only), `HsmPrivateKeyStore`, `CloudKmsPrivateKeyStore`.

### 7.2 Reference model (DB)

Table `egs_private_key_references` (proposed):

| Column | Notes |
|--------|-------|
| `id`, `workspace_id`, `egs_unit_id`, `environment` | Scoped |
| `store_driver` | e.g. `kms`, `hsm`, `encrypted_blob` |
| `store_key` | Opaque handle **only** |
| `public_key_fingerprint` | Link to cert SPKI/fingerprint |
| `status` | `active`, `revoked`, `destroyed` |
| **No** `private_key_pem` | Forbidden |

### 7.3 Lifecycle

1. **Generate:** Key created inside store during CSR generation (3D); DB gets reference only.
2. **Use:** `CryptographicStampSigner` receives `PrivateKeyReference` via resolver; signing happens inside store boundary.
3. **Rotate:** New key ref + new CSR + new cert; old ref `revoked` after cutover.
4. **Revoke/destroy:** Mark ref destroyed; signer fail-closed if used.

### 7.4 Retrieval boundary

Only `EInvoiceCryptographicStampService` (or dedicated `SigningService`) may call `PrivateKeyStore::sign`. Controllers and API **never** receive PEM.

### 7.5 Audit

Log: `workspace_id`, `egs_unit_id`, `environment`, `credential_id`, `operation`, `result` — **never** key material, OTP, or CSID secret.

---

## 8. CSID Model

**In-repo conclusion (Phase 9A):** CSID is realized as **ZATCA-issued X.509** for the EGS; Compliance vs Production types exist; OAuth uses certificate as client id + **issued secret** (Portal manual — not verified against live API in this repo).

### 8.1 `EgsCredential` metadata (no plaintext secrets)

| Field | Compliance | Production |
|-------|------------|------------|
| `kind` | `compliance` | `production` |
| `environment` | sandbox/production | sandbox/production |
| `egs_unit_id` | FK | FK |
| `e_invoice_certificate_id` | FK to public cert | FK to public cert |
| `private_key_reference_id` | FK | FK |
| `zatca_request_id` | Optional external id | Optional |
| `status` | pending / active / revoked / superseded | same |
| `issued_at`, `expires_at` | From cert / API | From cert / API |
| `revoked_at`, `last_used_at` | Optional audit | Optional audit |
| `secret_ref` | Opaque vault key for OAuth secret | Opaque vault key |

**Do not store:** raw CSID secret, OTP, CSR PEM with embedded key (store CSR in onboarding table optionally as **public CSR only** — open decision).

### 8.2 Identifier vs certificate

**Open decision:** Whether `EgsCredential.id` is the app CSID identifier or a separate ZATCA serial — until API contract verified, treat **certificate serial + fingerprint** as stable public identifier.

---

## 9. Onboarding State Machine

States are **per** `(egs_unit, environment)` on `EgsOnboarding`.

### 9.1 Proposed states

| State | Meaning | Allows | Forbids | Typical trigger |
|-------|---------|--------|---------|-----------------|
| `unconfigured` | No CSR | Read status | Signing, ZATCA API | EGS created |
| `csr_generated` | Key ref + CSR exist locally | Submit CSR (future) | Production signing | User/admin generates CSR (3D) |
| `compliance_pending` | Awaiting OTP / compliance CSID API | Retry onboarding | Production signing | CSR submitted |
| `compliance_active` | Compliance credential active | Compliance API calls, production CSID request | Production invoice signing | ZATCA compliance CSID stored |
| `production_pending` | Awaiting production CSID | Poll/retry | Production signing | Production request submitted |
| `production_active` | Production credential active | Production signing (when signer enabled) | — | Production CSID stored |
| `rotation_required` | Cert near expiry or policy | New CSR flow | New stamps with expiring cred | Scheduler / admin |
| `revoked` | ZATCA or operator revoked | Re-onboard only | All signing | Revocation event |
| `failed` | Terminal error with reason | Admin reset (policy) | Signing | API error |

### 9.2 Transitions (high level)

```text
unconfigured → csr_generated → compliance_pending → compliance_active
  → production_pending → production_active
production_active → rotation_required → csr_generated (new cycle)
* → revoked (external)
* → failed (recoverable → unconfigured or csr_generated per policy)
```

**Actor:** Workspace admin / finance settings permission (exact permission TBD in 3J).

---

## 10. Identity Resolution

### 10.1 Proposed service

`ActiveIdentityResolver` (new, `App\Services\EInvoicing\Security\`):

**Input:** `workspaceId`, `egsUnitId` (optional → default EGS), `environment`, `purpose` (`production_signing` | `compliance_api`).

**Output:** `ResolvedIdentity` DTO: `egsUnit`, `credential`, `certificate` (model), `PrivateKeyReference`, `onboarding` (must be `production_active` for signing).

**Algorithm:**

1. Resolve EGS via `EgsUnitResolver::requireForWorkspace`.
2. Load `EgsActiveIdentity` for `(egs_unit, environment, purpose)` — **missing → fail-closed exception**.
3. Load credential; verify `status=active`, environment match, not expired/revoked.
4. Load certificate via FK; verify lifecycle + dates.
5. Load key ref; verify not revoked.
6. **Do not** read `finance_settings.zatca_certificate_serial`.

### 10.2 Call sites (future)

| Caller | Purpose |
|--------|---------|
| `EInvoiceCryptographicStampService::stamp` | `production_signing` |
| ZATCA HTTP client (3E+) | `compliance_api` or token exchange |
| Optional issue gate (product) | Block issue if required ZATCA and not `production_active` |

---

## 11. Legacy Fields (`finance_settings`)

| Field | Decision | Reason |
|-------|----------|--------|
| `zatca_integration_mode` | **MIGRATE → DEPRECATE** | Replace with workspace-level `e_invoicing_environment` or `EgsOnboarding`-derived status; keep column for backward-compatible API reads until Flutter updated |
| `zatca_certificate_serial` | **DEPRECATE** | Superseded by `e_invoice_certificates.serial_number` + active identity; never was wired to registry |
| `zatca_last_synced_at` | **REMOVE LATER** | Meaningful only after FATOORA/sync worker exists; until then **KEEP** nullable unused |

**Rule:** Identity resolver **must not** use these in production paths ( **Confirmed** design decision for 3B).

---

## 12. Future Migration Plan (sequence only)

| Migration | Contents | Backward compatibility |
|-----------|----------|------------------------|
| **M1** | `egs_onboarding` table + enums | Existing EGS rows get no onboarding until backfill job creates `unconfigured` |
| **M2** | Extend `e_invoice_certificates`: `environment`, `kind`, `lifecycle_status`, nullable FKs | Default `lifecycle_status=active` for existing `imported` + `test_fixture=true` → kind test |
| **M3** | `egs_private_key_references` | Empty until 3D |
| **M4** | `egs_credentials` + `secret_ref` column (varchar, not text secret) | Empty until 3E |
| **M5** | `egs_active_identities` | Nullable until activation UX |
| **M6** | Optional: `e_invoice_cryptographic_stamps.egs_credential_id` | Nullable; old stamps null |
| **M7** | Optional: `finance_settings.e_invoicing_environment` | Migrate from `zatca_integration_mode` where parseable |
| **M8** | Deprecation comments / API docs only — **no** drop of legacy columns until major version |

All migrations **additive** per HASEM core rules.

---

## 13. API Boundary (future contract — not implemented)

Base: `/api/finance/v1` with workspace header + Sanctum (existing).

### 13.1 Read-only

| Endpoint | Returns | Must not return |
|----------|---------|-----------------|
| `GET /egs-units` | id, uuid, name, status | — |
| `GET /egs-units/{id}/onboarding?environment=` | state, timestamps, error summary | OTP, CSR private, secrets |
| `GET /egs-units/{id}/identity?environment=` | active cert summary (serial, not_after, kind), onboarding state | PEM, secret, key ref internals |
| `GET /egs-units/{id}/certificates` | lifecycle, kind, env, dates, fingerprint prefix | Full PEM optional flag default **false** |

### 13.2 Mutating (admin permission)

| Endpoint | Action | Errors |
|----------|--------|--------|
| `POST /egs-units` | Create additional EGS | 403, 422 |
| `POST /egs-units/{id}/onboarding/csr` | Start CSR (3D) | 409 if wrong state |
| `POST /egs-units/{id}/onboarding/otp` | Submit OTP (3E) | 422, 429 rate limit |
| `POST /egs-units/{id}/onboarding/activate` | Mark active after ZATCA response | 409 fail-closed |
| `POST /egs-units/{id}/identity/rotate` | Begin rotation | 409 if stamps pending policy |

**HTTP:** 404 cross-workspace (route model binding + scope); 409 missing identity; 403 permission; never 200 with partial secrets.

---

## 14. Threat Model

| Threat | Current protection | Gap | Recommended control | Priority |
|--------|-------------------|-----|---------------------|----------|
| Cross-workspace credential access | WorkspaceScopedModel; resolver checks | No credential table yet | FK + resolver + 404 on mismatch | P1 |
| Wrong EGS selected | Default UUID per workspace | No explicit device picker | Explicit `egs_unit_id` on issue/stamp + audit | P2 |
| Sandbox → production secret use | N/A (no secrets) | No environment dimension | Environment on credential + resolver | P1 |
| Production → sandbox leakage | N/A | Same | Separate key refs per env | P1 |
| Stale active certificate | N/A | No active pointer | `EgsActiveIdentity` + expiry job | P1 |
| Revoked cert used | N/A | No revocation state | lifecycle_status + ZATCA sync | P1 |
| Expired cert used | Parser stores dates | Not enforced at stamp | Resolver checks `not_after` | P1 |
| Private key in DB | Import rejects PEM; column guard | — | Keep guard; CI check migrations | P0 guardrail |
| CSID secret in API | Stamp endpoint fail-closed | — | `secret_ref` only in vault | P1 |
| Logs leak secrets | Generic notices | OTP/CSR logging not present | Redaction policy + static analysis | P1 |
| Exception messages leak PEM | Phase 10 test on stamp JSON | Future endpoints | Sanitize exception renderer | P2 |
| Silent legacy fallback | Settings zatca fields unused in crypto | Could be misused later | Ban in resolver + static test | P1 |

---

## 15. Confirmed Decisions

| # | Decision | Evidence |
|---|----------|----------|
| C1 | Workspace is tenant; EGS owns ICV/PIH sequence | `EgsUnit`, Phase 7 |
| C2 | Public certs immutable in `e_invoice_certificates`; no private key in cert table | `EInvoiceCertificate`, `CertificateRegistry` |
| C3 | Default production signer deferred / fail-closed | `DeferredCryptographicStampSigner`, `InvoiceIssueService::requestProductionCryptographicStamp` |
| C4 | Issue pipeline runs ICV/PIH/hash without production stamp | `InvoiceIssueService`, Phase 10 doc |
| C5 | CSID is external ZATCA-issued identity (X.509), not locally minted | Phase 9A §3.9 |
| C6 | Compliance vs Production CSID types exist in ZATCA materials | Phase 9A Portal references |
| C7 | Multiple EGS units per workspace allowed by schema | `egs_units` migration |
| C8 | Identity resolver must not use `finance_settings` crypto fields | Settings controller strips updates; fields unused |

---

## 16. Open Decisions

| # | Topic | Classification | Notes |
|---|-------|----------------|-------|
| O1 | EGS production curve (P-256 vs secp256k1) | **Blocked** — Phase 9A UNRESOLVED | Do not generate production keys until resolved |
| O2 | Signing input (hash vs SignedInfo) | **Blocked** — Phase 9A | Blocks XAdES emission design detail |
| O3 | Separate ICV chain for sandbox vs production | **Needs verification** | Same EGS + same counters today |
| O4 | One EGS unit vs two units for sandbox/production | **Needs product decision** | Environment on credential may suffice without duplicating EGS |
| O5 | Store CSR (public only?) in DB | **Needs verification** | Privacy vs audit |
| O6 | OAuth secret format & rotation API | **Needs verification** | ZATCA API not in repo |
| O7 | Exact ZATCA base URLs and auth headers | **Needs verification** | No config in repo |
| O8 | Whether issue gate should require `production_active` for `zatca_requirement=required` | **Product decision** | Currently requires ICV/PIH/hash/XML only |
| O9 | Platform admin vs workspace admin for onboarding | **Product decision** | Permissions |
| O10 | Expose certificate PEM via API | **Product decision** | Default off recommended |
| O11 | Tag 9 / PCSID extraction | **Blocked** — Phase 9A | QR completion |
| O12 | HSM vs KMS vs encrypted-at-rest choice | **Infrastructure decision** | Before 3C implementation |

**Inferred (not confirmed):** ZATCA OAuth client id maps to certificate subject/serial — treat as **Needs verification**.

---

## 17. Phase 3C Dependencies

Phase **3C** (key architecture implementation) depends on:

1. Approval of **O4**, **O12** (key storage strategy).
2. Frozen **reference schema** from §4–§8 (this document).
3. No need to resolve **O1/O2** for **storage** alone — only for **production signing** (3G).

Deliverables for 3C: `PrivateKeyStore` interface + reference table migration + dev-only driver + tests without ZATCA HTTP.

---

## 18. Implementation Sequence (post-approval)

```text
3B — This document (audit + design) ✓
3C — PrivateKeyReference + PrivateKeyStore (dev driver) + guards
3D — CSR generation + onboarding states (csr_generated, compliance_pending)
3E — CSID credential rows + secret vault + ZATCA HTTP (sandbox first)
3F — Certificate lifecycle + EgsActiveIdentity + extended CertificateRegistry
3G — Production CryptographicStampSigner + resolver integration
3H — XAdES + QR 7–9 (after Phase 9A unblocks)
3I — FATOORA clearance/reporting
3J — Finance API + Flutter admin/readiness UI
```

---

## Appendix A — P1 blockers for implementation

1. **Key storage strategy (O12)** — must pick vault/HSM pattern before any production key generation.  
2. **Environment model approval (§5)** — credential + onboarding keyed by environment.  
3. **Active identity model (§6, §10)** — avoid ambiguous “latest cert” logic.  
4. **ZATCA API contract verification (O6, O7)** — before OTP/CSID HTTP coding.  
5. **Product rule for issue gate vs onboarding (O8)** — affects compliance UX.

---

## Appendix B — P2 improvements

1. Explicit EGS selection on issue (multi-branch).  
2. Scheduled expiry → `rotation_required`.  
3. Read-only PEM export for support (audited).  
4. Separate sandbox EGS units for ICV isolation (if O3 requires).  
5. Platform-level ZATCA config service (URLs, feature flags).

---

## Appendix C — Exact implementation order (after design approval)

1. Migrations M1–M5 (onboarding, cert extensions, key refs, credentials, active identity).  
2. Domain enums + models (workspace-scoped).  
3. `ActiveIdentityResolver` (fail-closed, no legacy fallback).  
4. `PrivateKeyStore` + reference CRUD (no PEM in DB).  
5. Extend `CertificateRegistry` for kind/env/lifecycle.  
6. Onboarding state machine service (no HTTP).  
7. ZATCA HTTP client + credential secret vault (3E).  
8. Wire signer + stamp service (3G).  
9. API (3J) + Flutter read models.  
10. Deprecate `finance_settings.zatca_*` in presenter docs.

---

## Appendix D — Files / tables / services affected later

| Area | Items |
|------|--------|
| **Tables** | `egs_onboarding`, `egs_credentials`, `egs_private_key_references`, `egs_active_identities`; extend `e_invoice_certificates`, optional `e_invoice_cryptographic_stamps` |
| **Models** | `EgsUnit`, `EInvoiceCertificate`, new models above |
| **Services** | `CertificateRegistry`, `EgsUnitResolver`, new `ActiveIdentityResolver`, `OnboardingService`, `Zatca*Client`, `EInvoiceCryptographicStampService`, `InvoiceIssueService` (optional gate) |
| **Providers** | `AppServiceProvider` signer binding |
| **HTTP** | New Finance V1 controllers; extend `FinanceClientPresenter`, `EInvoiceArtifactService` |
| **Tests** | Phase 7/9 isolation patterns; new IDOR tests for credentials |
| **Flutter** | `hasim_finance` settings + new onboarding screens |
| **Docs** | `HASEM_IMPLEMENTATION_STATUS.md`, Phase 3C+ decision logs |

---

## Appendix E — Decisions requiring your approval before Phase 3C

1. **O12** — Where private keys live (KMS/HSM/on-prem encrypted vault).  
2. **O4** — Single EGS with dual environment credentials **vs** separate EGS per environment.  
3. **O3** — Shared vs separate ICV/PIH counters for sandbox.  
4. **O8** — Should `zatca_requirement=required` eventually require `production_active` (not just ICV/PIH/hash)?  
5. **O9** — Who may run onboarding (workspace admin only vs platform)?  
6. **Default environment** for new workspaces (sandbox-only until explicit production promotion?).

---

```text
NO CODE CHANGE — DESIGN DOCUMENT ONLY
```
