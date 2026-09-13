# FINANCE PHASE B IMPLEMENTATION REPORT

Quotes / Estimates Core

## 1. Summary

Phase B adds a real Quote / Estimate document inside Finance. A quote is not a PDF-only export and is not an invoice.

What now works:

- Workspace-scoped `finance_quotes` and `finance_quote_items`
- Independent quote numbering (`Q-2026-0001` style), collision-safe and transaction-safe
- Required customer relationship on the existing `customers` table
- Free-text lines as a first-class feature (`product_id` nullable)
- Optional catalog product on a line
- Display unit (for example `متر`)
- Server-side quantity × unit price → discount → tax → total via the existing `TaxCalculationService`
- Document statuses `draft`, `issued`, and `cancelled`
- Company / customer / PDF snapshots captured on save and recaptured on issue
- Issued quotes are financially immutable
- Arabic Finance UI: index, create, edit draft, show, PDF
- Spatie permissions: `quotes.view`, `quotes.create`, `quotes.edit`, `quotes.issue`, `quotes.cancel`, `quotes.delete`
- PDF shows company snapshot, customer snapshot, free-text description, unit, quantities, prices, tax, totals, terms, and notes

Quotes do **not**:

- Post GL / journal entries
- Create payments, receipts, or payment links
- Enter the ZATCA invoice chain
- Send email / WhatsApp / SMS
- Accept or reject from the customer
- Convert to an invoice

Phase C/D remain unstarted.

## 2. Files Changed

| File | Change |
| --- | --- |
| `database/migrations/2026_09_10_140000_create_finance_quotes_tables.php` | `finance_quotes`, `finance_quote_items`, `finance_settings.quote_prefix` / `next_quote_sequence` |
| `app/Enums/Finance/QuoteStatus.php` | Document status: draft / issued / cancelled |
| `app/Enums/Finance/QuoteDeliveryStatus.php` | Future delivery enum only (`sent`, `viewed`) — unused in Phase B |
| `app/Enums/Finance/QuoteOutcomeStatus.php` | Future outcome enum only (`accepted`, `rejected`, `expired`, `converted`) — unused in Phase B |
| `app/Models/Finance/FinanceQuote.php` | Quote document, snapshots, issue lock |
| `app/Models/Finance/FinanceQuoteItem.php` | Lines, `lineTitle()`, `displayUnit()`, lock guard |
| `app/Models/Finance/FinanceSetting.php` | Quote numbering columns |
| `app/Models/Customer.php` | `financeQuotes()` |
| `app/Services/Finance/QuoteService.php` | Create / update draft / issue / cancel / delete draft / numbering / tax / snapshots |
| `app/Services/Finance/PdfQuoteService.php` | DomPDF download using existing Arabic shaping |
| `app/Services/Finance/FinanceBootstrapService.php` | Default `quote_prefix=Q`, `next_quote_sequence=1` |
| `app/Http/Controllers/Workspace/Finance/QuoteController.php` | Web CRUD, issue, cancel, PDF; strips client totals |
| `app/Providers/AppServiceProvider.php` | Workspace audit observers |
| `routes/web.php` | Finance-only quote routes |
| `database/seeders/FoundationSeeder.php` | Quote permissions on master list, roles, and Pro tier |
| `resources/views/workspace/finance/quotes/index.blade.php` | Quotes list |
| `resources/views/workspace/finance/quotes/create.blade.php` | Create / edit draft builder |
| `resources/views/workspace/finance/quotes/show.blade.php` | Quote show |
| `resources/views/workspace/finance/quotes/pdf.blade.php` | Quote PDF |
| `resources/views/workspace/finance/partials/sidebar.blade.php` | Sales → عروض الأسعار before invoices |
| `resources/views/workspace/finance/modules/sales.blade.php` | Quotes index + create actions |
| `tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php` | Phase B coverage |

No POS, Booking, Inbox, WhatsApp, AI, Flutter, ZATCA, or payment-engine files were modified.

## 3. Migrations

Additive only. No columns dropped. No existing tables rewritten.

`finance_quotes`:

- `workspace_id`, `customer_id` (FK to `customers`)
- `quote_number` unique per workspace
- `status` (`draft` / `issued` / `cancelled`)
- `issue_date`, `expiry_date`
- `currency`
- `subtotal`, `discount`, `taxable_amount`, `tax_amount`, `total`
- `tax_profile_type`, `tax_rate`, `tax_price_mode`, `tax_breakdown`
- `notes`, `terms`
- `company_snapshot`, `recipient_snapshot`, `pdf_snapshot`
- `created_by`, `issued_by`, `issued_at`, `cancelled_at`
- timestamps + soft deletes

`finance_quote_items`:

- `quote_id`
- `product_id` **nullable** FK to `products`
- `product_name`, `description`
- `unit` (display), `unit_code` (optional, not inferred from Arabic unit labels)
- `quantity`, `unit_price`, `discount`
- tax profile / exemption / rate / amounts / line total
- `metadata`

`finance_settings`:

- `quote_prefix` default `Q`
- `next_quote_sequence` default `1`

Not added:

- `finance_customers`
- delivery / send tables
- payment_status on quotes
- quote → invoice link column
- Flutter / public quote portal tables

## 4. Models

`FinanceQuote`:

- Workspace scoped, soft deletes
- Status helpers: `isDraft()`, `isIssued()`, `isCancelled()`, `isLocked()`
- Financial lock after issue/cancel (same pattern as invoices)
- Mutable after lock: `status` (issued → cancelled only), `cancelled_at`, `notes`
- Snapshots are authoritative once locked

`FinanceQuoteItem`:

- `product_id` nullable
- `lineTitle()` — `product_name`, else `description`
- `displayUnit()` — `unit`, else `unit_code`
- Creating / updating / deleting lines is blocked when the parent quote is locked

`Customer::financeQuotes()` is a read relation only. No `finance_customers` table.

`QuoteDeliveryStatus` and `QuoteOutcomeStatus` exist as unused enums so later phases can add delivery and commercial outcome without overloading `status`.

## 5. Services

`QuoteService`:

- `create()` — always assigns a quote number; calculates server-side totals; captures snapshots; optional immediate issue if the actor requested `issued` and has `quotes.issue`
- `updateDraft()` — draft only
- `issue()` — recaptures company/customer/PDF snapshots, sets `issued_at` / `issued_by`, locks financials
- `cancel()` — issued → cancelled; no GL reverse because quotes never posted
- `deleteDraft()` — draft only
- `nextQuoteNumber()` — `lockForUpdate` on `finance_settings`, format `{prefix}-{year}-{sequence:04d}`

`QuoteService` does **not** call:

- `InvoiceService`
- `InvoiceIssueService`
- `IssuedSnapshotBuilder`
- journal / inventory posting
- payment services
- ZATCA XML / QR / CSID / clearance

`PdfQuoteService` reuses DomPDF + `ArPHP` glyph shaping from invoice PDFs.

`TaxCalculationService` is reused; no new tax engine.

## 6. Routes

Finance web group only (`workspace.feature:finance`, names `workspace.finance.quotes.*`):

| Name | Method | Path |
| --- | --- | --- |
| `quotes.index` | GET | `finance/quotes` |
| `quotes.create` | GET | `finance/quotes/create` |
| `quotes.store` | POST | `finance/quotes` |
| `quotes.show` | GET | `finance/quotes/{quote}` |
| `quotes.edit` | GET | `finance/quotes/{quote}/edit` |
| `quotes.update` | PUT | `finance/quotes/{quote}` |
| `quotes.destroy` | DELETE | `finance/quotes/{quote}` |
| `quotes.pdf` | GET | `finance/quotes/{quote}/pdf` |
| `quotes.issue` | POST | `finance/quotes/{quote}/issue` |
| `quotes.cancel` | POST | `finance/quotes/{quote}/cancel` |

Not added: `quotes.send`, `quotes.accept`, `quotes.reject`, `quotes.convert`, Flutter API, public portal.

No Finance invoice API was extended. Quote HTTP API is not required by the current Finance web pattern and was not built.

Existing credit-note cancel remains `POST invoices/{invoice}/credit-notes/{creditNote}/cancel`.

## 7. UI

Finance → Sales → **عروض الأسعار** (before الفواتير).

- Index: search, status pipeline (الكل / مسودة / صادر / ملغى), totals, PDF link
- Create / edit draft: customer, auto quote number, issue date, expiry, notes, terms, multi-line builder
- Each line: optional product (“بند حر”), name, unit, description, qty, unit price, discount, tax classification (standard / zero_rated / exempt / out_of_scope), tax rate
- Live preview totals are approximate; server recalculates and discards client `total` / `tax_amount` / `taxable_amount`
- Show: company + customer snapshot, lines with unit, totals, terms/notes, issue / delete draft / cancel issued
- Sales module page links to quotes index and create

Arabic copy matches the current Finance layout (`layouts.financial`). POS / Booking / Inbox navigation was not touched.

## 8. PDF

`resources/views/workspace/finance/quotes/pdf.blade.php` + `PdfQuoteService`.

Shows:

- Company name, VAT, CR, address (from `company_snapshot` when issued)
- Customer name, VAT, CR (from `recipient_snapshot` when issued)
- Quote number, issue date, expiry date, document status
- Lines: title (free-text description when `product_id` is null), unit, qty, unit price, discount, tax, line total
- Subtotal, discount, VAT, grand total
- Terms and notes
- Footer: this is a quote, not a tax invoice

Draft PDFs may fall back to live Finance settings. Issued PDFs use frozen snapshots.

No payment badge, amount due, or ZATCA QR.

## 9. Permissions

Existing Spatie + `FinanceBaseController` (permission **or** owner/admin/manager membership). No new authorization architecture.

| Permission | Use |
| --- | --- |
| `quotes.view` | index, show, PDF |
| `quotes.create` | create / store |
| `quotes.edit` | edit / update draft |
| `quotes.issue` | issue, or store with status=issued |
| `quotes.cancel` | cancel issued |
| `quotes.delete` | delete draft |

Not added: `quotes.send`, accept, reject, convert.

Role defaults:

- Owner / admin: all (via full permission sync)
- Manager: view / create / edit / issue
- Agent: view
- Accountant: view / create / edit / issue / cancel
- Pro plan entitlements include the six quote permissions including delete

## 10. Tax handling

Reuses `App\Services\Finance\Tax\TaxCalculationService::calculateDocument`.

Supported classifications (same as invoices):

- Standard VAT
- Zero rated
- Exempt
- Out of scope

Price modes: exclusive (default) and inclusive.

Line math (exclusive, matching invoices):

1. Gross = quantity × unit price
2. Discount bounded to gross
3. Taxable = gross − discount
4. Tax = taxable × rate when standard
5. Line total = taxable + tax
6. Document totals = sum of lines

Client-submitted totals are stripped in `QuoteController::validatedQuotePayload`.

## 11. Quote numbering

Independent of invoice numbering.

- Format: `{quote_prefix}-{YYYY}-{NNNN}` → `Q-2026-0001`
- Workspace scoped (`unique(workspace_id, quote_number)`)
- `lockForUpdate` on `finance_settings`
- Sequence skips collisions including soft-deleted quotes
- Creating quotes does not increment `next_invoice_sequence`

Workspace A and Workspace B can both hold `Q-2026-0001`.

## 12. Quote lifecycle

Document status only (`QuoteStatus`):

```text
draft → issued → cancelled
draft → (delete)
```

Issued and cancelled are locked. Issued quotes cannot be edited (lines, prices, tax, totals). Drafts can be updated or deleted. Issued quotes can be cancelled without GL posting.

Delivery (`sent`, `viewed`) and outcome (`accepted`, `rejected`, `expired`, `converted`) are **not** stored or transitioned. They are separate enums for later phases so one status field does not mix document + delivery + payment.

Quotes are not receivables. There is no `payment_status`, amount due, or receipt.

The line/customer/notes/terms shape is copyable later for Quote → Invoice. Conversion is not implemented.

## 13. Tests added

`tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php` (9 tests, 88 assertions):

1. Create draft and update draft (server-side totals after price change)
2. Issue quote: locks financials; no journal, invoice, payment, or `IssuedDocumentSnapshot`
3. Issued quote cannot be edited (subtotal stays `100.00`)
4. Quote number generated (`Q-YYYY-NNNN`), workspace scoped, does not advance invoice sequence
5. Workspace A cannot show/issue Workspace B quote (404)
6. Free-text `عزل أسطح` / `متر` / 500 / 45 plus `تنظيف أسطح` 500×23, catalog product, discount, fake client totals ignored
7. Zero-rated, exempt, and out-of-scope lines tax = 0
8. PDF HTML contains description, unit, qty, unit price, customer, company snapshot, quote number; binary PDF download exists
9. Agent with view/edit/create cannot issue
10. Navigation under Finance Sales; send/convert/accept routes do not exist
11. Issued quote cannot be deleted; cancel works without journals

Expected VAT example (exclusive 15%):

| Line | Gross | Discount | Taxable | Tax | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| عزل أسطح 500×45 | 22500 | 500 | 22000 | 3300 | 25300 |
| تنظيف أسطح 500×23 | 11500 | 0 | 11500 | 1725 | 13225 |
| Catalog 2×10 | 20 | 0 | 20 | 3 | 23 |
| Zero-rated | 50 | 0 | 50 | 0 | 50 |
| Exempt | 40 | 0 | 40 | 0 | 40 |
| Out of scope | 30 | 0 | 30 | 0 | 30 |
| **Document** | **34140** | **500** | **33640** | **5028** | **38668** |

## 14. Tests updated

None of the existing Finance / ZATCA / customer / POS tests were rewritten.

`FinancePhaseBQuotesTest` originally attempted to mock final `InvoiceIssueService`; that mock was removed. Isolation is asserted through missing journals, invoices, payments, and issued snapshots.

## 15. Full test results

Commands:

```text
php vendor/bin/phpunit tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php --testdox
php vendor/bin/phpunit tests/Feature/Feature/Finance tests/Unit/Finance tests/Unit/EInvoicing tests/Feature/Feature/Workspace/WorkspaceModulesNavigationTest.php tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php --no-coverage
php vendor/bin/phpunit tests/Feature/Feature/Workspace tests/Unit/Appointments tests/Feature/Feature/Finance/Phase3PosIssuedSnapshotTest.php tests/Feature/Feature/Finance/FinancePhaseAFoundationTest.php tests/Unit/Finance/InvoicePresentationTest.php --no-coverage
```

| Suite | Result |
| --- | --- |
| Phase B quotes | **9 tests, 88 assertions, passed**, ~2.4s |
| Finance feature + Finance unit + E-invoicing unit + workspace navigation + tenancy isolation | **338 tests, 336 passed, 2 skipped, 1 risky, 1 warning**, 2761 assertions, ~31.7s, **exit 0** |
| `tests/Feature/Feature/Workspace` (includes Omnichannel Inbox) | 10 tests, 55 assertions, passed |
| `tests/Unit/Appointments` | 3 tests, passed |
| Phase A foundation (re-run) | included in the extra batch, passed |
| Phase 3 POS issued snapshot (re-run) | included in Finance suite, passed |

No failing tests in the suites above.

## 16. Any skipped/risky tests

Pre-existing, not introduced by Phase B:

Skipped:

- `Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv`
- `Phase7EgsConcurrencyIntegrationTest::test_parallel_workers_allocate_unique_icvs_on_server_database`

Risky:

- `Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables` (no assertions; already special-cased)

Warning (pre-existing OpenSSL parse on a test cert):

- `app/EInvoicing/Security/X509CertificateParser.php:21 openssl_x509_read(): X.509 Certificate cannot be retrieved`

No live browser session was available in this environment (no app `.env` / GUI server). Quote UI and PDF behavior were verified through HTTP feature tests: create form, index navigation, PDF Blade HTML, and PDF download `Content-Type: application/pdf`.

## 17. Risks

- Owner/admin/manager still bypass specific Spatie checks via `FinanceBaseController` (same as invoices). Agents are permission-gated.
- Quote notes remain writable after issue (same invoice pattern). Financial lines/totals are frozen.
- Display `unit` is a human label. ZATCA `unit_code` is not guessed from Arabic units and is unused for quotes.
- Draft PDFs can still read live company settings; issued PDFs freeze snapshots.
- Quote numbers are allocated at draft create, so deleted drafts consume a sequence value (collision-safe by design).
- Quote → Invoice conversion is intentionally absent; later work must copy lines rather than treat a quote as a receivable.

## 18. Follow-up for Phase C

Not in this PR:

- Send quote (email / WhatsApp / SMS)
- Delivery records and `QuoteDeliveryStatus` persistence (`sent`, `viewed`)
- Public quote portal / viewed tracking
- Customer accept / reject (`QuoteOutcomeStatus`)
- Quote expiry job
- Quote → Invoice conversion
- Payment links / receipts (should remain out of quote core; quotes are not AR)

Phase C should persist delivery separately from document `status`.

## 19. Confirmation that Send / Accept / Reject / Convert were NOT implemented

Confirmed.

- No `quotes.send` permission
- No send/email/WhatsApp/SMS actions or routes
- No public quote portal
- No accept / reject UI, routes, or permissions
- No `converted_invoice_id` / conversion service
- Tests assert `workspace.finance.quotes.send`, `.convert`, and `.accept` routes do not exist

## 20. Confirmation that POS / Booking / Inbox / AI / Flutter / ZATCA architecture were NOT changed

| Area | Status |
| --- | --- |
| POS | Not changed (Phase 3 POS snapshot tests still pass) |
| Booking | Not changed (`tests/Unit/Appointments` still pass) |
| Inbox | Not changed (`OmnichannelInboxTest` still pass) |
| WhatsApp | Not changed |
| AI | Not changed |
| Flutter | Not changed; no new mobile API |
| ZATCA architecture | Not changed (no XML/QR/CSID/clearance/reporting edits; quotes never call `InvoiceIssueService`) |
| Payment engine | Not changed |
| Invoice numbering | Not changed |
| Tax engine | Reused, not replaced |

## Success criteria

- [x] Quote entity exists
- [x] Quote lines exist
- [x] Workspace isolation works
- [x] Quote numbering works
- [x] Customer relationship works
- [x] Product is optional
- [x] Free-text line works
- [x] Unit works
- [x] Quantity works
- [x] Unit price works
- [x] Discount works
- [x] VAT works
- [x] Server-side totals work
- [x] Draft works
- [x] Issued works
- [x] Issued quote cannot be edited
- [x] Quote PDF works
- [x] Free-text description appears in PDF
- [x] Unit appears in PDF
- [x] Customer data appears in PDF
- [x] Company snapshot appears in PDF
- [x] Permissions work
- [x] Tests pass
- [x] Existing Finance/ZATCA tests remain passing
- [x] No POS changes
- [x] No Booking changes
- [x] No Inbox changes
- [x] No AI changes
- [x] No Flutter changes
- [x] No ZATCA architecture changes
- [x] No email sending yet
- [x] No WhatsApp
- [x] No payment link
- [x] No receipt
- [x] No Quote → Invoice yet
