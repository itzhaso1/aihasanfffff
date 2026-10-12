# Final Project Readiness Audit

**Date:** 10 September 2026  
**Branch:** `cursor/finance-final-readiness-audit-5a63`  
**Base:** Phase 10 (`cursor/finance-phase10-api-readiness-5a63`, `18265be`)  
**Scope:** HASEM Laravel backend — Finance / POS / e-invoicing Phases 1–10  

**Verdict:** READY WITH DOCUMENTED LIMITATIONS

This audit does not start another architecture phase. Production ZATCA cryptographic stamping remains an **external / specification blocker**, not an internal project defect.

---

## 1. Executive Summary

The implemented HASEM backend scope is internally coherent. Laravel is the source of truth for invoice identity, numbering, tax, issued snapshots, e-invoice classification, UBL XML, ICV / PIH / invoice hash, and derived QR Tags 1–6. The `/api/finance/v1` surface is authenticated, workspace-scoped, and suitable as the future Flutter Invoice App contract.

No internal blocking defect was found that requires a code change before project closure. Production ZATCA cryptographic stamping is still blocked by unresolved official specification conflicts (Phase 9A). POS cashier invoices remain unclassified for ZATCA type/transaction codes by design.

A clean production-code audit is intentional: **no application, migration, route, or test files were modified**.

---

## 2. Completed Phases

| Phase | Subject | Status |
| --- | --- | --- |
| 1 | Critical finance fixes | Accepted |
| 2 | Immutable Finance issued snapshots | Accepted |
| 3 | POS issued snapshots | Accepted |
| 4 | Tax data hardening | Accepted |
| 5 | E-invoice domain foundation | Accepted |
| 5A | Snapshot completeness | Accepted |
| 6 | UBL 2.1 XML foundation | Accepted |
| 7 | ICV / PIH / invoice hash security chain | Accepted |
| 8 | QR foundation (Tags 1–6) | Accepted |
| 9 | Cryptographic stamp foundation | Accepted (deferred production signer) |
| 9A | ZATCA cryptographic specification reconciliation | Accepted (documentation only) |
| 9B | Local test-only cryptographic integration harness | Accepted (test isolation) |
| 10 | Application / API readiness | Accepted PASS WITH NOTES |

Canonical pipeline:

```text
Business Document
  → IssuedDocumentSnapshot (immutable legal payload)
  → EInvoiceDocument (classification + compliance state)
  → UBL 2.1 XML (deterministic, when eligible)
  → ICV / PIH / invoice_hash (Phase 7, single hasher)
  → QR Tags 1–6 (derived; not a second source of truth)
  → Production cryptographic stamp  [BLOCKED / EXTERNAL]
```

---

## 3. Finance Readiness

Lifecycle `draft → issued → cancelled` is enforced in `InvoiceService`:

- Issue retries on an already-issued invoice reconnect the snapshot / e-invoice pipeline and return success (idempotent).
- Cancelled invoices cannot be issued.
- Draft-only update and delete; issued/cancelled documents are financially locked.
- Payment status (`unpaid` / `partial` / `paid` / `overdue`) is separate from invoice status.
- Numbering uses `lockForUpdate` on `finance_settings` plus unique `(workspace_id, invoice_number)`.
- Tax at issue is reconciled against persisted amounts; it is not recalculated from current configuration.
- Credit/debit notes, contract billing, and recurring billing remain on the Finance path with snapshot capture on note issue.

Race handling: issue, numbering, and snapshot persist run inside transactions; snapshot uniqueness is `(workspace_id, source_type, source_id)` with duplicate-key reuse.

---

## 4. POS Readiness

POS remains a separate domain (`PosCashierInvoice` + `PosTaxCalculator`). Finance `TaxCalculationService` is not used on POS order/invoice tax.

Closing a cashier invoice:

```text
PosOrderService::ensureIssuedSnapshot
  → IssuedSnapshotBuilder::capturePosCashierInvoice
  → InvoiceIssueService::prepareFromSnapshot
```

POS electronic documents persist as `document_kind = pos_cashier_invoice` with **null** type/transaction codes. XML, security chain, and QR are skipped until a future classification decision. Unclassified POS documents do not pretend to be production ZATCA documents.

Intentionally deferred:

- ZATCA standard vs simplified classification for POS
- POS XML / ICV / QR generation
- Cashier invoice numbering is **globally** unique (`invoice_number` unique, not per workspace). Isolation is still `workspace_id`.
- Schema default currency for POS cashier invoices is `USD` (pre-existing POS schema, not a Finance rewrite).
- Closed POS invoices may still be edited in the cashier domain; the issued snapshot is **not** rewritten (Phase 3 rule). Live cashier rows can therefore diverge from the legal snapshot.

---

## 5. Tax Readiness

| Path | Engine | Historical behavior |
| --- | --- | --- |
| Finance invoices / notes | `TaxCalculationService` + `Money` minor units | Persisted rates/amounts; issue reconciles, does not rebuild from current settings |
| POS | `PosTaxCalculator` only | Persisted on order and cashier invoice; snapshot `tax.engine = pos` |
| Snapshots / XML / QR | Copied from snapshot projection | No live tax recalculation |
| Reports | Use persisted issued values | Not recalculated from current VAT config |

No duplicate production hash/tax formula was introduced after Phase 4/7. Floats appear at some service boundaries; arithmetic goes through `Money` cents. Credit/debit note totals are persisted on issue and applied to the original invoice remaining amount.

---

## 6. Snapshot Readiness

`IssuedDocumentSnapshot` is immutable (model update/delete hooks throw). Unique source identity is `(workspace_id, source_type, source_id)`. Payload schema version 2 (Phase 5A) includes seller, buyer, invoice number, dates, supply date, currency, lines, tax, totals, payment, references, notes, and subtype.

Retry reuses the existing row (`IssuedSnapshotBuilder::persist` + unique constraint). Historical snapshots are not rebuilt from current database state.

---

## 7. E-Invoice Domain Readiness

- Factory `EInvoiceFactory::persist` is the only creator of `e_invoice_documents`.
- Unique on snapshot id and `(workspace_id, source_type, source_id)`.
- Rows are immutable except `compliance_status`.
- In-memory `EInvoiceDocument` is a snapshot projection; it does not query live Finance/POS tables.
- Business status and compliance status remain separate.
- Purchase invoices: `not_applicable`. POS: `ready` without type/transaction codes.

---

## 8. XML Readiness

Controllers do not build XML. Generation is `EInvoiceXmlGenerator` → `UblMapper` → deterministic UBL 2.1. Same snapshot projection produces the same XML. Invoice / credit note / debit note, seller, buyer, lines, tax, totals, references, supply date, unit code, payment means, exemptions, and structured addresses are mapped from the document.

Ineligible kinds (purchase, unclassified POS, missing type/transaction codes, incomplete BR-KSA seller/buyer) skip XML without failing the business issue. XSD validation exists; production XAdES is not implemented.

---

## 9. Security Chain Readiness

Phase 7 remains the only invoice-hash implementation (`InvoiceHashService`). QR and the Phase 9B harness consume the persisted `invoice_hash`; they do not re-hash.

Verified:

- Monotonic ICV per EGS unit; unique `(egs_unit_id, icv)`
- First PIH constant; subsequent PIH = previous invoice hash
- Canonicalization C14N 1.1 with QR / Signature / UBLExtensions excluded
- Security records immutable and 1:1 with `EInvoiceDocument`
- Retry reuses ICV / PIH / hash
- Cross-workspace EGS use is rejected

SQLite `:memory:` cannot prove multi-writer uniqueness. Genuine fork tests are skipped unless `TEST_DB_CONNECTION=mysql|pgsql` with a matching live connection. Sequential uniqueness **is** covered.

---

## 10. QR Readiness

Phase 8 TLV Tags 1–6: seller name, VAT, timestamp, total including VAT, VAT amount, invoice hash. UTF-8 + Base64. Tag order is official. QR is derived, not persisted, and does not allocate ICV, mutate PIH, or create security records.

API QR refuses cryptographic Tags 7–9 (`production_crypto_unavailable` if a payload includes them). Tag 6 remains the Phase 8 44-character Base64 text of the Phase 7 hash. That representation conflict is **not** “fixed” here.

---

## 11. Cryptographic Status

Production container bindings:

- `CryptographicStampSigner` → `DeferredCryptographicStampSigner`
- `CryptographicProfile` → `UnresolvedProductionZatcaCryptographicProfile` (`production.zatca.unresolved`)
- `QrTag9Provider` → `UnresolvedProductionQrTag9Provider`

`production.zatca.unresolved` fails closed. `CryptographicProfileGuard` rejects test profiles/signers in `production`. `POST /api/finance/v1/.../cryptographic-stamp` always throws `ProductionCryptoUnavailableException` with **no** test-signer fallback.

Private keys exist only under `tests/Fixtures/EInvoicing/`. Certificate registry and X.509 parser reject private-key PEM. No `private_key` columns. No FATOORA HTTP in `app/`.

Test-only classes live under `app/EInvoicing/**/Harness` and `TestCryptographicStampSigner` for explicit test construction. They are not production defaults.

---

## 12. API Readiness

Prefix `/api/finance/v1`. Middleware: `auth:sanctum`, `workspace.resolve`, `workspace.member`, throttle.

| Surface | Notes |
| --- | --- |
| Invoice list/detail/issue/XML/QR/PDF | DTOs + JsonResources; pagination `per_page` 1–100 on invoice list |
| Notes list/detail/issue/XML/QR | Draft note **detail** returns `compliance_unavailable` (409); list still returns drafts |
| POS list/detail/XML/QR | XML/QR `compliance_unavailable`; detail requires issued snapshot |
| Cryptographic stamp | Always `production_crypto_unavailable` |

Envelope: `{ success, data, meta }` / `{ success, message, code, errors }`. Finance API catch-all returns a generic `server_error` without stack traces.

Does not expose: private keys, test profile IDs, ICV/PIH as top-level fields, internal payloads, or `production.zatca.unresolved`.

Flutter contract: Laravel supplies number, status, tax, totals, snapshot-backed details, XML, QR Tags 1–6, compliance flags. Flutter must not reproduce tax, numbering, ICV, PIH, hash, XML, or ZATCA crypto.

API gaps that are **not** bugs:

- Notes and POS list do not accept `per_page` (fixed page size 25; `page` still works).
- Draft Finance invoices have details; draft notes do not (409 until issue).
- Invoice hash is delivered via QR Tag 6, not as a details field (ICV/PIH intentionally omitted).

---

## 13. Authorization / Workspace Isolation

Finance API authorization matches web Finance: Spatie permission, `workspace.manage`, or active membership `owner` / `admin` / `manager`. There is no dedicated `InvoicePolicy`; controllers use `AuthorizesFinanceApi`.

Route model binding uses `WorkspaceScopedModel` with workspace context. Cross-workspace invoice IDs return **404** (scope), not 403. Same-workspace missing permission returns **403**. Unauthenticated returns **401**.

`withoutGlobalScopes()` in services is paired with explicit `workspace_id` (or unique document identity). Finance API controllers do not strip scopes.

`SettingsController::shouldDeleteLogoFile` queries invoices without a workspace filter when deciding whether a logo file is still referenced. That is a conservative delete guard, not a data leak.

---

## 14. Idempotency / Concurrency

| Operation | Guarantee |
| --- | --- |
| Finance/note issue (already issued) | Same document; snapshot/e-invoice reconnect |
| Snapshot create | Unique `(workspace, source_type, source_id)`; retry returns existing |
| EInvoiceDocument persist | Unique snapshot id / source tuple |
| XML | Pure function of the snapshot projection |
| Security | Unique document id; ICV reused |
| QR | Derived; not stored |
| API issue | HTTP success on retry, not `already_issued` |

True MySQL/PostgreSQL multi-writer ICV tests are **not** claimed. phpunit.xml uses SQLite `:memory:`. Sequential uniqueness is tested. Parallel uniqueness is skipped.

---

## 15. Database Integrity

Relevant uniqueness:

- `finance_invoices (workspace_id, invoice_number)`
- `pos_cashier_invoices.invoice_number` (global)
- `issued_document_snapshots (workspace_id, source_type, source_id)`
- `e_invoice_documents` snapshot id + source tuple
- `e_invoice_security_records` document id + `(egs_unit_id, icv)`
- `e_invoice_cryptographic_stamps` document id
- `e_invoice_certificates` fingerprint per workspace

Security/stamp FKs use `restrictOnDelete` on documents/EGS. Workspace teardown is not a product flow; cascading workspace delete vs immutable/restrict rows is an operational limitation, not a runtime invoice bug. No migration was added.

Unused placeholder columns on `finance_invoices`: `zatca_uuid`, `zatca_xml_hash` (and related). E-invoice tables are the source of truth. Leaving them unused is safer than a speculative drop.

---

## 16. Security Findings

Classified scan (representative; not every Guzzle/domain HTTP call):

| Hit | Classification |
| --- | --- |
| `dd()` / `dump()` / `var_dump()` in `app/` | None |
| `eval` / `exec` / `shell_exec` / `passthru` in `app/` | None |
| `verify=false` / `withoutVerifying` | None |
| CSRF exceptions (`webhooks/resend`, `whatsapp-webhook`) | Safe (external webhook receivers) |
| Test PEM keys | Test-only (`tests/Fixtures/EInvoicing/`) |
| `TestCryptographicStampSigner` / test profiles | Test-only; guarded in production |
| `DeferredCryptographicStampSigner` | Production (fails closed / deferred) |
| Guzzle / `Http::` | Production for domains/email/etc.; **no** FATOORA/ZATCA HTTP in e-invoicing |
| `APP_DEBUG` | `config/app.php` defaults false; `.env.example` true for local (Laravel default) |
| Namecheap request logs | Safe (command/status only; no API key) |
| E-invoice operational logs | Workspace/source ids and skip reasons; no PEM/secrets |
| `InvoiceAlreadyIssuedException` | Dead API type; unused because retries succeed |

No private-key exposure, no test-crypto API leakage, no production→test crypto fallback.

---

## 17. Performance Findings

No production-impacting defect was fixed.

- Invoice/note/POS lists are paginated and batch-load e-invoice records.
- Invoice **detail** generates XML once to set `xml_available` (derived flag, not persisted). Acceptable at current volume.
- XML/security/QR are not stored as mutable rows; retries reuse identities rather than reallocating ICV.
- No unbounded Finance API list (max 100 per page on invoices).

---

## 18. Test Results

### Full suite

```text
php artisan test
```

| Metric | Count |
| --- | --- |
| Tests | 635 |
| Assertions | 4623 |
| Passed | 630 |
| Failed | 1 |
| Skipped | 4 |
| Risky | 1 |
| Warnings | 1 |

**Failure (pre-existing, outside e-invoicing scope):**  
`Tests\Feature\Feature\Workspace\WorkspaceModulesNavigationTest::test_workspace_dashboard_shows_grouped_modules_with_existing_routes`  
expects the string `POS / Cashier`. After the POS ops split the dashboard/sidebar title is `POS / الإدارة`. This is a stale navigation assertion, not a Finance/e-invoice defect. It was **not** rewritten in this audit.

**Skipped:**

1. `Phase7EgsConcurrencyIntegrationTest` — requires live mysql/pgsql + `pcntl`
2. `Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv` — same
3. `CentralEmailServiceTest::it_sends_email_through_central_service_and_stores_log` — array-mailer crash in this environment
4. `CentralEmailServiceTest::notification_channel_uses_central_email_service` — same

**Risky:**  
`Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables` — query log is empty (XML mapper issues no SQL), so the loop performs zero assertions. Behavior is correct; the test is marked risky by PHPUnit.

**Warning:**  
`X509CertificateParser` `openssl_x509_read(): X.509 Certificate cannot be retrieved` — expected path when rejecting malformed PEM in unit tests.

### Phase 1–10 + tax regression

```text
php artisan test --filter='Phase1CriticalFixesTest|Phase2IssuedSnapshotTest|Phase3PosIssuedSnapshotTest|Phase4TaxDataHardeningTest|Phase5EInvoiceDomainTest|Phase5ASnapshotCompletenessTest|Phase6UblXmlFoundationTest|Phase7EInvoiceSecurityChainTest|Phase7EgsConcurrencyIntegrationTest|Phase8ZatcaQrFoundationTest|Phase9CryptographicStampFoundationTest|Phase9BLocalCryptographicHarnessTest|Phase10InvoiceApiContractTest|Phase10InvoiceLifecycleIntegrationTest|TaxEngineHardeningTest|InvoiceDomainHardeningTest|TaxEngineTest'
```

| Metric | Count |
| --- | --- |
| Tests | 170 |
| Assertions | 1717 |
| Passed | 168 |
| Failed | 0 |
| Skipped | 2 (Phase 7 concurrency) |
| Risky | 1 (Phase 6 empty query log) |

### Static / quality tooling

| Tool | Result |
| --- | --- |
| Composer audit | No security vulnerability advisories found |
| Laravel Pint | Present; no PHP files changed, so not run as a rewrite |
| PHPStan / Larastan | Not configured; not installed for this audit |

---

## 19. Remaining Known Limitations

These are intentional or environmental. They are **not** internal blockers:

1. POS remains unclassified for ZATCA; no POS XML/security/QR.
2. Incomplete BR-KSA seller/buyer snapshot: business issue succeeds; XML/QR skipped.
3. `InvoiceIssueService` swallows XML/security/QR failures so Finance/POS issue is not blocked; operators must use skip logs.
4. Draft note and unsnapshotted POS **detail** APIs return 409 `compliance_unavailable`.
5. Notes/POS list APIs do not take `per_page`.
6. POS cashier `invoice_number` uniqueness is global, not per workspace.
7. POS schema default currency `USD`.
8. POS closed-invoice edits do not rewrite the legal snapshot.
9. SQLite test DB cannot prove multi-writer ICV uniqueness.
10. Unused `finance_invoices.zatca_uuid` / `zatca_xml_hash` placeholders.
11. `InvoiceAlreadyIssuedException` is unused (retries are success).
12. Workspace delete vs `restrictOnDelete` on security/stamp rows.
13. `.env.example` has `APP_DEBUG=true` for local development.
14. Full `php artisan test` has one stale workspace navigation assertion from the POS ops split.
15. Central email feature tests skipped in this environment.
16. Flutter application is not built in this repository.
17. Production XAdES, CSID, and FATOORA are out of scope.

---

## 20. External Dependencies

Unresolved ZATCA specification items (Phase 9A). Do not treat code as having chosen these:

1. ECDSA curve — NIST P-256 vs secp256k1
2. Signing input — invoice hash vs XMLDSig `SignedInfo`
3. Signature encoding — DER vs IEEE P1363
4. QR Tag 6 representation — 32-byte digest vs 44-char Base64 UTF-8 (Phase 8 keeps 44-char)
5. QR Tags 7 / 8 encoding
6. QR Tag 9 encoding (meaning is a ZATCA CA artifact; encoding unresolved)
7. Certificate / SigningCertificate digest details
8. XMLDSig SignatureMethod URI
9. Production XAdES-B-B profile confirmation
10. CSID / CSR / OTP provisioning
11. FATOORA production HTTP integration
12. Non-exportable production private-key module (HSM)

---

## 21. Production Readiness Verdict

### Internal HASEM readiness

**READY WITH DOCUMENTED LIMITATIONS**

The implemented backend scope (Phases 1–10) is complete and internally coherent. Authorization, workspace isolation, snapshots, tax persistence, XML determinism, the Phase 7 hash chain, derived QR Tags 1–6, and the Finance API contract hold. No internal defect found in this audit required a code fix.

### ZATCA production cryptographic readiness

**BLOCKED (external / specification)**

Not a project-closure blocker. Do not bind a production signer, provision CSID, or call FATOORA until Phase 9A conflicts are resolved by an authoritative ZATCA clarification.

### Recommendation

**PROJECT READY FOR CLOSURE.**

Do not start Phase 11. Do not build Flutter in this repository. Do not implement production ZATCA crypto by assumption. Stop.
