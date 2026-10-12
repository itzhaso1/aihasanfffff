# Phase 10 — Application / API Readiness & Invoice Lifecycle Integration

**Status:** Implemented for review  
**Date:** 10 September 2026

Laravel remains the source of truth for invoice identity, numbering, business status, tax, VAT, legal snapshots, classification, XML, ICV, PIH, invoice hash, and compliance state. A future Flutter Invoice App is a client of this API. Flutter must not become a second source of truth for those values.

Production ZATCA cryptographic stamping remains **BLOCKED**. This phase does not resolve Phase 9A conflicts, call FATOORA, provision CSIDs, or enable a production signer.

---

## 1. Canonical invoice lifecycle

Business status and electronic-invoice compliance stay separate.

```text
FinanceInvoice.invoice_status / PosCashierInvoice.status / FinanceCreditNote.status
        +
EInvoiceDocumentRecord.compliance_status
```

### Business lifecycle (unchanged)

```text
Draft → Issued → Cancelled
```

Payment status (`unpaid` / `partial` / `paid` / `overdue`) remains a Finance concern and is not a compliance state.

### Compliance lifecycle (E-Invoice domain)

```text
Draft (no electronic document)
  ↓ issue / POS close
Ready
  ↓ XML + Phase 7 security (when eligible)
Generated
  ↓ future submission worker (not this phase)
Queued → Submitted → Cleared | Reported | Rejected | Failed
```

Purchase invoices persist as `not_applicable`. POS cashier invoices persist as `ready` and do not guess ZATCA type/transaction codes.

---

## 2. Finance integration

Issuing a Finance sales invoice now runs:

```text
InvoiceService::issue
    → IssuedSnapshotBuilder::captureInvoice
    → InvoiceIssueService::prepareFromSnapshot
        → EInvoiceFactory::persist
        → EInvoiceXmlGenerator::generate   (when type/transaction codes exist)
        → EInvoiceSecurityService::generate (Phase 7 ICV/PIH/hash)
        → EInvoiceQrService                 (derived Tags 1–6, not persisted)
```

Retries of `issue` reuse the same snapshot, `EInvoiceDocument`, ICV, and security record.

Tax is not recalculated at issue. Phase 4/5A persisted amounts on the invoice and snapshot remain authoritative.

---

## 3. POS integration

POS stays a separate domain (`PosCashierInvoice` + `PosTaxCalculator`). Closing a cashier invoice still:

```text
PosOrderService::ensureIssuedSnapshot
    → capturePosCashierInvoice
    → InvoiceIssueService::prepareFromSnapshot
```

POS tax on the order, cashier invoice, snapshot (`tax.engine = pos`), and `EInvoiceDocument` must match. Finance `TaxCalculationService` is not used.

POS electronic documents keep `document_kind = pos_cashier_invoice` with null `type_code` / `transaction_code`. XML, security, and QR are not generated until a future phase classifies POS as standard or simplified. That classification is **not** invented here.

---

## 4. Credit / debit notes

`CreditNoteService::issue` captures an immutable snapshot (original invoice number/date/subtype + note reason and line/tax values) and runs the same `InvoiceIssueService` pipeline.

Classification follows Phase 5/6: notes inherit the original invoice subtype. XML uses the existing type/transaction codes. ZATCA transaction-code rules are unchanged.

---

## 5. EInvoiceDocument boundary

`EInvoiceFactory::persist` remains the only creator of `e_invoice_documents`. Identity is unique on `issued_document_snapshot_id` and `(workspace_id, source_type, source_id)`. Rows are immutable except `compliance_status`.

The in-memory `EInvoiceDocument` is a snapshot projection. It does not query live Finance/POS tables and does not recalculate tax.

---

## 6. Snapshot boundary

`IssuedDocumentSnapshot` is the legal source for electronic-document generation. It is created once per source document and cannot be updated or deleted.

POS edits after close still do not rewrite snapshots (existing Phase 3 rule). The snapshot remains the legal document even if a later POS mutation exists.

---

## 7. XML boundary

```text
API / InvoiceIssueService
    → EInvoiceXmlGenerator
    → UblMapper
    → deterministic UBL 2.1 XML
```

Controllers do not build XML. XML is not stored as a mutable row. Generation is deterministic from the snapshot projection. Purchase and POS kinds are ineligible.

---

## 8. QR boundary

```text
API / InvoiceIssueService
    → EInvoiceQrService
    → TLV Tags 1–6 (Phase 8 unsigned profile)
```

QR is derived from the snapshot seller/VAT/totals plus the Phase 7 invoice hash. It is not persisted. The API returns `qr_base64` plus named Tags 1–6 (`seller_name`, `seller_vat`, `timestamp`, `total_with_vat`, `vat_total`, `invoice_hash`). Tags 7–9 are not emitted. Production cryptographic QR fields remain unavailable.

---

## 9. Security boundary

Phase 7 ICV / PIH / invoice hash semantics are unchanged. Security records remain immutable and one-to-one with `EInvoiceDocument`.

Production stamping:

- Default signer is still `DeferredCryptographicStampSigner`.
- Container `CryptographicProfile` is still `UnresolvedProductionZatcaCryptographicProfile`.
- `POST .../cryptographic-stamp` returns `production_crypto_unavailable`.
- The API never binds or falls back to `TestCryptographicStampSigner`.

Private keys, test profile identifiers, DER/P1363 choices, and harness artifacts are not part of invoice API responses.

---

## 10. API contract

Prefix: `/api/finance/v1`  
Auth: Sanctum + `X-Workspace-Id` + workspace membership.

Envelope (same shape as Cashier, plus `code` on errors):

```json
{ "success": true, "data": {}, "meta": {} }
{ "success": false, "message": "...", "code": "...", "errors": {} }
```

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/invoices` | `invoices.view` |
| GET | `/invoices/{invoice}` | `invoices.view` |
| POST | `/invoices/{invoice}/issue` | `invoices.issue` |
| GET | `/invoices/{invoice}/xml` | `invoices.view` |
| GET | `/invoices/{invoice}/qr` | `invoices.view` |
| GET | `/invoices/{invoice}/pdf` | `invoices.view` (existing `PdfInvoiceService`) |
| POST | `/invoices/{invoice}/cryptographic-stamp` | `invoices.view` → always unavailable |
| GET/POST | `/notes`, `/notes/{note}`, xml, qr, issue, stamp | `invoices.view` / `invoices.credit` |
| GET | `/pos-invoices`, `/pos-invoices/{posInvoice}` | `invoices.view` |
| GET | `/pos-invoices/{posInvoice}/xml\|qr` | `invoices.view` → `compliance_unavailable` for POS |

Responses use DTOs / JsonResources (`InvoiceSummary`, `InvoiceDetails`, `InvoiceLine`, `InvoiceTaxSummary`, `InvoiceCompliance`, `InvoiceReferences`). Eloquent models are not dumped.

Error codes: `unauthorized`, `forbidden`, `not_found`, `validation_failed`, `cannot_issue`, `already_issued`, `idempotency_conflict`, `compliance_unavailable`, `production_crypto_unavailable`.

Repeated `POST .../issue` on an already-issued document is **success** (idempotent), not `already_issued`. Issuing a cancelled document is `cannot_issue`.

---

## 11. Authorization model

Finance API authorization matches web Finance:

- Spatie permission, or
- `workspace.manage`, or
- active membership role `owner` / `admin` / `manager`

Workspace isolation uses `WorkspaceScopedModel` plus membership middleware. Workspace A cannot read Workspace B invoices, snapshots, e-invoice documents, security records, XML, or QR.

---

## 12. Idempotency guarantees

Safe to retry:

| Operation | Guarantee |
| --- | --- |
| Issue (already issued) | Same business document + same snapshot |
| Snapshot create | Unique `(workspace, source_type, source_id)` |
| EInvoiceDocument persist | Unique snapshot id / source tuple |
| XML generate | Pure function of the snapshot projection |
| Security generate | Unique document id; ICV reused |
| QR generate | Derived; not stored |

---

## 13. Flutter / Laravel responsibility split

**Laravel (authoritative)**

- Invoice number and identity
- Tax, VAT, totals
- Legal issued snapshot
- E-invoice classification
- XML, ICV, PIH, invoice hash
- Compliance and future ZATCA submission state

**Flutter (client)**

- UI, local display state, caching
- User interaction
- Printing/rendering of API-provided XML/QR/PDF payloads
- Offline UX, then sync through Laravel

Flutter must display Laravel values. It must not invent invoice numbers, tax, or compliance states.

---

## 14. Production ZATCA crypto remains blocked

Unresolved Phase 9A decisions (do not treat API fields as having chosen these):

1. ECDSA curve (NIST P-256 vs secp256k1)
2. Signing input (invoice hash vs XMLDSig SignedInfo)
3. Signature encoding (DER vs IEEE P1363)
4. QR Tag 7 / 8 / 9 encoding
5. QR Tag 6 representation (Phase 8 default remains 44-character Base64 text)
6. Certificate / SigningCertificate digest details
7. XMLDSig SignatureMethod URI

External blockers: CSID provisioning, Tag 9 as a ZATCA CA artifact, FATOORA HTTP, production XAdES.

`POST /api/finance/v1/invoices/{id}/cryptographic-stamp` fails closed with `production_crypto_unavailable`. It does not use the Phase 9B test harness.
