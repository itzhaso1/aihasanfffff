# FINANCE PHASE D IMPLEMENTATION REPORT

Quote Outcome + Accept/Reject + Quote → Invoice

## 1. Summary

Phase D keeps **document status** (`draft` / `issued` / `cancelled`) separate from **commercial outcome** (`pending` / `accepted` / `rejected` / `expired` / `converted`).

What now works:

- Issued quotes start with `outcome=pending`
- Internal Finance **Accept** (`quotes.accept`) — no invoice is created
- Internal Finance **Reject** (`quotes.reject`) — optional reason; rejected quotes cannot convert
- Internal Finance **Convert** (`quotes.convert`) — accepted issued quotes only
- Conversion reuses existing `InvoiceService::create()` and produces a **draft** sales invoice
- Invoice number comes from the existing Finance numbering sequence (not the quote number)
- Quote ↔ invoice link via unique nullable `converted_invoice_id`
- Concurrent / repeated convert requests cannot create a second invoice, journal, ZATCA snapshot, or convert audit
- Quote show page (Arabic) reflects outcome and shows Accept / Reject / Convert / invoice link accordingly
- Expiry is enforced on accept/convert as a server-side rule; **no scheduled expiry job**

Acceptance and conversion are separate actions. Conversion does **not** issue, post GL, or create ZATCA snapshots. Issuance stays on the existing invoice Issue action.

Not implemented: public quote portal, tokenized URLs, viewed tracking, customer self-service, WhatsApp, SMS, Inbox, AI, Flutter, POS, Booking, payment links, receipts, invoice email, reminders, recurring billing, payroll, inventory, ZATCA rewrite, new payment engine.

## 2. Architecture used

Safest conversion path (no duplicated tax / numbering / GL / snapshot / ZATCA logic):

```text
Issued quote (outcome=pending)
  → POST accept  → outcome=accepted (quote still non-financial)
  → POST convert
       lockForUpdate(quote)
       if already converted → return existing invoice (no second create, no second audit)
       InvoiceService::create(status=draft)
            TaxCalculationService (from line inputs, not client totals)
            existing invoice numbering
            no issue() → no GL, no InvoiceIssueService, no ZATCA snapshot
       quote.outcome=converted, converted_invoice_id=invoice.id
  → later: existing POST invoices/{invoice}/issue
       InvoiceService::issue()
            GL posting
            IssuedSnapshotBuilder
            InvoiceIssueService::prepareFromSnapshot()
```

The quote itself is never a receivable. No payment, receipt, or payment link is created by accept or convert.

## 3. Files changed

| File | Change |
| --- | --- |
| `app/Enums/Finance/QuoteOutcomeStatus.php` | Added `pending`; Arabic labels for all outcomes |
| `database/migrations/2026_09_10_180000_add_quote_outcome_and_conversion_columns.php` | Additive outcome + conversion columns |
| `app/Models/Finance/FinanceQuote.php` | Outcome helpers, conversion relation, locked-field allowlist |
| `app/Services/Finance/QuoteService.php` | `accept()`, `reject()`, `convert()` via `InvoiceService` + audit |
| `app/Http/Controllers/Workspace/Finance/QuoteController.php` | `accept` / `reject` / `convert` |
| `routes/web.php` | POST accept / reject / convert |
| `resources/views/workspace/finance/quotes/show.blade.php` | Outcome UI, Accept / Reject / Convert / invoice link |
| `database/seeders/FoundationSeeder.php` | `quotes.accept`, `quotes.reject`, `quotes.convert` |
| `tests/Feature/Feature/Finance/FinancePhaseDQuoteOutcomeTest.php` | Phase D coverage |
| `tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php` | Routes now exist; issued quotes start pending |
| `tests/Feature/Feature/Finance/FinancePhaseCSendQuoteTest.php` | Issued pending show page includes Accept / Reject |

No POS, Booking, Inbox, WhatsApp, AI, Flutter, payment-engine, or ZATCA architecture files were modified.

## 4. Migrations

`2026_09_10_180000_add_quote_outcome_and_conversion_columns` (additive on `finance_quotes`):

- `outcome` string(16) default `pending`, index `(workspace_id, outcome)`
- `accepted_at`, `accepted_by` (FK `users`, null on delete)
- `rejected_at`, `rejected_by` (FK `users`, null on delete)
- `rejection_reason` text nullable
- `converted_invoice_id` nullable FK `finance_invoices` (`restrictOnDelete`), **unique**
- `converted_at`, `converted_by` (FK `users`, null on delete)

No existing columns dropped or renamed. Invoice tables were not rewritten.

## 5. Routes

All POST-only, workspace-prefixed Finance web routes. No public/tokenized URLs.

| Method | Path | Name |
| --- | --- | --- |
| POST | `finance/quotes/{quote}/accept` | `workspace.finance.quotes.accept` |
| POST | `finance/quotes/{quote}/reject` | `workspace.finance.quotes.reject` |
| POST | `finance/quotes/{quote}/convert` | `workspace.finance.quotes.convert` |

## 6. Permissions

Existing Spatie + `FinanceBaseController::authorizeFinance`. No new auth system.

| Permission | Purpose |
| --- | --- |
| `quotes.accept` | Accept issued pending quote |
| `quotes.reject` | Reject issued pending quote |
| `quotes.convert` | Convert accepted quote to draft invoice |

Seeded on:

- Master permission list
- Manager role
- Accountant role
- Pro plan entitlements
- Owner/admin receive all permissions (unchanged)

Agent without these permissions receives 403 even with `quotes.view` / `quotes.edit` / `quotes.issue` / `quotes.send`.

## 7. Outcome rules

Document status stays `draft` | `issued` | `cancelled`.

| From | Accept | Reject | Convert |
| --- | --- | --- | --- |
| Draft | No | No | No |
| Issued + pending | Yes (if not past expiry) | Yes | No |
| Issued + accepted | No | No | Yes (if not past expiry, not already converted) |
| Issued + rejected | No | No | No |
| Issued + converted | No | No | Idempotent: return existing invoice |
| Cancelled | No | No | No |
| Past `expiry_date` | Blocked | Allowed while pending | Blocked |

Converted quotes cannot be cancelled (preserves the invoice link).

`expired` exists on the enum but is **not** written by a job. Past expiry is an explicit server-side block on accept/convert only.

## 8. Quote → invoice conversion flow

1. Controller checks `quotes.convert` and current workspace owns the quote (else 404).
2. `QuoteService::convert()` opens a transaction and `lockForUpdate()`s the quote row.
3. If `converted_invoice_id` is already set, return that quote/invoice. No second invoice, journal, snapshot, or `quote_converted` audit.
4. Validate issued + accepted + not past expiry + customer in the same workspace.
5. Build an `InvoiceService::create()` payload from frozen quote lines:
   - customer, currency, tax profile/rate/price mode
   - line `product_id` (nullable), `product_name`, `description`, `unit`, `unit_code`, quantity, unit price, discount, tax classification/rate, exemption fields
   - notes + terms as `payment_terms`
   - **no** quote number as invoice number
   - **no** client/stored totals, `amount_paid`, or issue flag
6. `InvoiceService::create()` with `invoice_status=draft` / `status=draft` recalculates tax and assigns a normal invoice number. `$shouldIssue` is false, so `issue()` / `InvoiceIssueService` is not called.
7. Persist `outcome=converted`, `converted_invoice_id`, `converted_at`, `converted_by`.
8. Write audit `quote_converted` with quote id/number, workspace, actor, invoice id/number, timestamp.

The resulting invoice is a normal Finance sales invoice. Issue / GL / ZATCA happen only through the existing invoice issue flow.

## 9. Idempotency / concurrency

Duplicate conversion is prevented by:

1. `SELECT … FOR UPDATE` on the quote inside a transaction
2. Early return when `converted_invoice_id` is already set (no second audit)
3. Unique constraint on `finance_quotes.converted_invoice_id`

Two simultaneous convert requests cannot create two invoices, two journals, or two ZATCA snapshots.

## 10. UI

Quote show page (Arabic, internal Finance only):

- Document badge + commercial outcome badge
- Issued pending: **قبول العرض** + **رفض العرض**; Convert hidden
- Accepted: accepted state + **تحويل إلى فاتورة**; Reject hidden
- Rejected: rejected state + reason; no Convert
- Converted: converted state + link to generated invoice; no second Convert
- Past expiry note on pending quotes
- No public accept/reject URLs

## 11. Audit

| Action | When |
| --- | --- |
| `quote_accepted` | Successful accept |
| `quote_rejected` | Successful reject (includes rejection reason) |
| `quote_converted` | First successful convert only (includes invoice id/number) |

Each event stores quote id, quote number, workspace, actor, timestamps (`occurred_at` plus accept/reject/convert timestamps in values/meta).

## 12. Security / workspace

- Quote must belong to current workspace (`assertSameWorkspace` → 404)
- Customer must belong to the same workspace (`requireCustomer`)
- Generated invoice `workspace_id` must match the quote
- Cross-workspace accept/convert returns 404
- Permissions checked server-side
- Client-submitted totals / invoice numbers on convert are ignored
- Quote is not a receivable; accept does not post GL

## 13. Tests added

`tests/Feature/Feature/Finance/FinancePhaseDQuoteOutcomeTest.php` (13 tests, 181 assertions):

1. Issued quote starts pending; quote is not a receivable; no payment/GL
2. Authorized user can accept; no invoice created; audit `quote_accepted`
3. Unauthorized agent gets 403 on accept/reject/convert
4. Draft cannot be accepted
5. Cancelled cannot be accepted
6. Authorized reject persists reason; no Convert in UI
7. Accepted cannot be rejected; rejected cannot be accepted or converted
8. Accepted quote converts to draft invoice; normal invoice number; catalog + free-text (`product_id=NULL`) lines; unit/tax/discount copied; client totals ignored; same workspace; `converted_invoice_id` stored
9. Converting twice / stale in-memory convert does not create a second invoice, journal, snapshot, or convert audit; unique index present
10. Convert does not call issue/GL/ZATCA; existing `invoices.issue` still posts GL and creates issued snapshots
11. Cross-workspace access returns 404
12. Past expiry blocks accept/convert; reject still allowed
13. Show page actions follow outcome rules; routes are POST-only; no WhatsApp

Coverage mapped to the Phase D checklist: items 1–30 are asserted across these tests plus the Finance/ZATCA regression suite (existing invoice issue, no POS/Booking/Inbox/WhatsApp/AI changes, existing tests remain green).

## 14. Tests updated

- Phase B: issued quotes assert `outcome=pending`; convert/accept/reject routes now exist
- Phase C: send show page now includes internal Accept / Reject for issued pending quotes (still no WhatsApp)

## 15. Full test results

```text
php vendor/bin/phpunit tests/Feature/Feature/Finance/FinancePhaseDQuoteOutcomeTest.php --testdox --no-coverage
# OK (13 tests, 181 assertions)

php vendor/bin/phpunit tests/Feature/Feature/Finance/FinancePhaseDQuoteOutcomeTest.php \
  tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php \
  tests/Feature/Feature/Finance/FinancePhaseCSendQuoteTest.php --no-coverage
# OK (31 tests, 357 assertions)

php vendor/bin/phpunit tests/Feature/Feature/Finance tests/Unit/Finance tests/Unit/EInvoicing \
  tests/Feature/Feature/Workspace tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php \
  tests/Unit/Appointments --no-coverage
# 370 tests, 368 passed, 2 skipped, 1 risky, 1 warning, 3066 assertions, ~37.8s, exit 0
```

| Suite | Result |
| --- | --- |
| Phase D quote outcome | 13 tests, passed |
| Phase B + C + D quotes | 31 tests, passed |
| Finance feature + Finance unit + E-invoicing + Workspace + tenancy + Appointments | 370 tests, 368 passed, exit 0 |

Phase C’s comparable regression run was 357 tests / 355 passed. The +13 tests are the new Phase D file. Existing Finance and ZATCA tests remain green.

## 16. Existing skipped / risky / warnings

Pre-existing, not introduced by Phase D (same as Phase C):

Skipped:

- `Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv`
- `Phase7EgsConcurrencyIntegrationTest::test_parallel_workers_allocate_unique_icvs_on_server_database`

Risky:

- `Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables`

Warning:

- `app/EInvoicing/Security/X509CertificateParser.php:21` — `openssl_x509_read(): X.509 Certificate cannot be retrieved`

## 17. Confirmations

- **No POS / Booking / Inbox / WhatsApp / AI / Flutter changes**
- **No payment-engine changes**
- **No ZATCA architecture changes** (conversion never calls `InvoiceIssueService`; issue remains the existing invoice path)
- **Duplicate conversion is prevented** (`lockForUpdate` + unique `converted_invoice_id` + idempotent return without a second audit)
- **Quote is not a receivable**; accept does not post GL
- **No payment / receipt / payment link** is created by accept or convert
