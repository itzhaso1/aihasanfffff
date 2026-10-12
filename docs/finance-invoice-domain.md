# Finance invoice domain (Phase 1)

This document describes the **business invoice** domain after Phase 1 hardening.

It does **not** describe ZATCA compliance. The platform is not connected to Fatoora, does not generate XML or QR, and does not claim e-invoicing clearance or reporting.

## FinanceInvoice responsibilities

`FinanceInvoice` is the workspace-scoped **business invoice**.

It owns:

- sales/purchase direction (`type`)
- draft → issued → cancelled lifecycle (`invoice_status`)
- payment state (`payment_status`, amounts paid/due)
- lines, tax classification, totals
- seller/buyer/PDF snapshots
- invoice numbering
- source attachments
- GL posting at issue and GL reversal at cancel

It does **not** own:

- ZATCA HTTP calls
- XML submission, clearance, or reporting
- CSID / Fatoora retries
- POS cashier invoices

Future e-invoicing is a separate layer:

```
FinanceInvoice  →  Structured Invoice  →  Future E-Invoicing Domain
```

Placeholder columns (`zatca_uuid`, `zatca_qr_code`, `zatca_xml_hash`) stay unused in this phase. They must not be filled with fake values.

## Lifecycle

1. **Create** always persists a **draft** first (number assigned, lines calculated, snapshots captured).
2. If the caller asked for `invoice_status = issued` (create form, purchase-order conversion, billing-schedule `auto_issue`), `InvoiceService::issue()` runs in the same transaction.
3. **Issue** is the only finalization path: period guard, refresh snapshots from current customer/supplier/settings, set `issued_at` in `config('app.timezone')`, post GL, lock financial content.
4. **Payments** change payment state only.
5. **Credit/debit notes** reference the issued invoice and adjust credited/debited totals only.
6. **Cancel** is explicit, auditable, and reverses the invoice GL. It is not ZATCA cancellation.

Global application timezone is unchanged (`UTC` in `config/app.php`). Issue timestamps use `now(config('app.timezone'))`.

`issue_date` remains the business date chosen on the draft. `issued_at` is the immutable issuance datetime. Both are kept; no extra datetime column was added.

## Immutable fields (issued or cancelled)

Once `invoice_status` is `issued` or `cancelled`, these cannot change:

- customer / customer name / supplier
- invoice number
- issue date / issued_at
- currency, type, tax document subtype, zatca_requirement
- tax profile, tax rate, lines, quantities, prices, discounts, VAT, taxable amounts, totals
- seller snapshot, buyer snapshot, PDF snapshot
- source attachments (add/update/delete)

Allowed after issue:

- `payment_status`, `amount_paid`, `amount_due`
- `amount_credited`, `amount_debited`
- reminder fields
- `cancelled_at` and `invoice_status` only as `issued → cancelled`
- operational `notes`
- unused ZATCA placeholder columns (future layer only)

Draft invoices remain editable via `InvoiceService::updateDraft()`. Issued invoices cannot be deleted; cancel instead.

## Payment state

`invoice_status` and `payment_status` stay separate.

Payments are separate transactions. Recording or reversing a payment must not rewrite lines, tax, snapshots, or invoice number.

## Invoice classification

Keep `type = sales | purchase`.

Additional fields:

| Field | Values | Meaning |
| --- | --- | --- |
| `tax_document_subtype` | `standard`, `simplified` | Tax-document shape. Default for historical rows: `standard`. |
| `zatca_requirement` | `not_required`, `required` | Internal flag only. Default: `not_required`. |

Rules:

- Purchase invoices are always `not_required`.
- Sales invoices are **not** assumed ZATCA-required.
- Setting `required` does not call ZATCA and does not generate XML/QR.

## Tax boundary

Invoice tax calculation is owned by `App\Services\Finance\Tax\TaxCalculationService`.

See `docs/finance-tax-engine.md` for classifications, rate resolution, rounding, mixed-tax invoices, and historical persistence.

`TaxService` remains a compatibility facade (expenses / purchase orders). Controllers and Blade must not be the source of truth for VAT.

This Tax Engine is **not** a ZATCA integration.

## Snapshot source of truth

At issue (and refreshed at issue even if create-directly-as-issued):

- `company_snapshot` from that workspace’s `FinanceSetting` (explicit `workspace_id`)
- `recipient_snapshot` from customer or supplier (or cash customer name)
- `pdf_snapshot` for PDF theme/footer

For issued/cancelled invoices, snapshots are authoritative. PDF and show views must not reconstruct seller/buyer/VAT from live Customer or FinanceSetting data.

Draft invoices may still preview live settings.

## Numbering

- Automatic numbering is the default: prefix + zero-padded sequence, `lockForUpdate` on `finance_settings`, skip occupied numbers (including soft-deleted and cancelled). Sequence gaps are acceptable.
- Historical numbers are never rewritten or reused.
- Manual numbers are **off** for new workspaces (`allow_manual_invoice_numbers = false`).
- Existing `finance_settings` rows were backfilled to `true` so current operators who typed numbers in the form keep that capability until they turn it off.
- When enabled, the number must be unique per workspace (including cancelled/soft-deleted). It cannot bypass the unique index.

## Attachments

All `finance_invoice_attachments` are source attachments. After issue they are immutable: no add, update, or delete. Existing files are not deleted by this phase. Download remains allowed.

## Credit / debit notes

`finance_credit_notes` is unchanged as a business-note model.

Notes reference the original issued invoice, calculate server-side, persist line `tax_profile_type`, and must not rewrite original invoice lines. They only update `amount_credited` / `amount_debited` and then payment status.

No ZATCA BillingReference XML in this phase.

## Cancellation

Cancel is explicit (`invoice_cancelled` audit). GL for the invoice is reversed. This is **not** ZATCA-compliant cancellation. Silent delete of issued/cancelled invoices is forbidden.

## Workspace safety

Invoice-domain settings reads use explicit `workspace_id` (`FinanceSetting::forWorkspaceId()` / `withoutGlobalScopes()->where('workspace_id', …)`).

Do not use `FinanceSetting::first()` on invoice/PDF/settings paths when the workspace is known.

`WorkspaceScopedModel` remains in place; it is not a substitute for explicit workspace filters on cross-tenant writes.

## Audit events

`WorkspaceAuditObserver` continues to emit:

- `invoice_created`
- `invoice_issued`
- `invoice_cancelled`
- `invoice_updated_draft`
- `payment_added`
- `payment_reversed`
- `credit_note_created`
- `debit_note_created`

## Historical backfill

| Field | Historical default |
| --- | --- |
| `tax_document_subtype` | `standard` |
| `zatca_requirement` | `not_required` (purchases forced) |
| `issued_at` | `created_at` when issued/cancelled and `issued_at` was null |
| line `tax_profile_type` | copy of invoice header tax profile, else `standard` |
| `allow_manual_invoice_numbers` | `true` for settings rows that already existed |

No fake UUID / ICV / PIH / QR is generated for old invoices.

## Intentionally not implemented

- ZATCA API, XML, QR, CSID, Fatoora, production e-invoicing
- `/api/finance`
- POS / cashier invoice integration
- Invoice or contracts UI redesign
- ZATCA cancellation rules
