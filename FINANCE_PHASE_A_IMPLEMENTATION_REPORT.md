# FINANCE PHASE A IMPLEMENTATION REPORT

## 1. Summary

Phase A (Finance Foundation) is implemented on the shared HASEM customer and invoice stack. No Quotes, send-invoice, payment-link, receipt, POS, Booking, Inbox, WhatsApp, AI, Flutter, payment-engine, or ZATCA architecture work was done.

What now works:

- The existing `customers` table stores Individual/Company identity plus VAT, commercial registration, free-text address, structured address, and contact fields.
- Customer outstanding AR is calculated from issued sales invoices (`SUM(amount_due)`). That figure already nets posted payments and issued credit/debit notes. `customers.balance` is kept as a stored cache and is not the source of truth.
- Invoice lines remain catalog-optional. `product_id` may be null. A display `unit` column (for example `متر`) is persisted separately from ZATCA `unit_code`.
- The invoice create UI accepts a free-text line (name, unit, quantity, unit price, discount, tax) without creating a Product.
- Invoice PDF and the invoice show page use `lineTitle()` (product name, else description) and `displayUnit()`.
- Totals still come from the existing `TaxCalculationService`. Client-submitted line totals are discarded.

## 2. Files Changed

| File | Change |
| --- | --- |
| `database/migrations/2026_09_10_120000_add_finance_phase_a_foundation_columns.php` | Added `customers.party_type`, `finance_invoice_items.unit` |
| `app/Http/Requests/Customer/CustomerPayloadRules.php` | Shared financial validation |
| `app/Http/Requests/Customer/StoreCustomerRequest.php` | Merges financial rules |
| `app/Http/Requests/Customer/UpdateCustomerRequest.php` | Merges financial rules |
| `app/Http/Controllers/Workspace/CustomerController.php` | Defaults `party_type`, uppercases `country_code` |
| `app/Models/Customer.php` | `party_type`, party helpers, `storedBalance()` |
| `app/Policies/CustomerPolicy.php` | Finance members with invoice/customer permissions may update |
| `database/factories/CustomerFactory.php` | Defaults `party_type` to individual |
| `app/Services/Finance/CustomerBalanceService.php` | Movement-based outstanding |
| `app/Http/Controllers/Workspace/Finance/ModulePageController.php` | Finance customers list uses calculated outstanding |
| `app/Models/Finance/FinanceInvoiceItem.php` | `unit`, `lineTitle()`, `displayUnit()` |
| `app/Services/Finance/InvoiceService.php` | Free-text title, optional `product_id`, persist `unit` |
| `app/Http/Controllers/Workspace/Finance/InvoiceController.php` | Strip client totals; coerce `product_id`/`unit` |
| `app/Services/Finance/IssuedSnapshotBuilder.php` | Additive `unit` on finance invoice lines only |
| `resources/views/workspace/customers/form.blade.php` | Party type, VAT, CR, address fields |
| `resources/views/workspace/customers/index.blade.php` | Type and VAT columns |
| `resources/views/workspace/finance/modules/customers.blade.php` | Type, VAT, calculated outstanding, edit link |
| `resources/views/workspace/finance/invoices/create.blade.php` | Free-text name, unit, optional product |
| `resources/views/workspace/finance/invoices/show.blade.php` | Unit column, `lineTitle()` |
| `resources/views/workspace/finance/invoices/pdf.blade.php` | Title fallback + unit column |
| `tests/Feature/Feature/Finance/FinancePhaseAFoundationTest.php` | Phase A coverage |

## 3. Database Changes

Additive only. No columns dropped.

- `customers.party_type` — `string(16)`, default `individual` (`individual` \| `company`).
- `finance_invoice_items.unit` — `string(32)`, nullable display unit (for example `متر`). Placed after `unit_code` when that column exists.

Not added:

- `finance_customers`
- `unit` on POS lines or credit-note items
- Any change to `customers.balance`

Existing customer financial columns reused: `vat_number`, `commercial_registration`, `address`, `building_number`, `street`, `district`, `city`, `postal_code`, `country_code`, `additional_number`, `payment_terms`.

## 4. Model Changes

`Customer`:

- Fillable `party_type`.
- `PARTY_TYPE_INDIVIDUAL` / `PARTY_TYPE_COMPANY`.
- `partyType()`, `isCompany()`.
- `storedBalance()` documents that `balance` is a cache, not AR truth.

`FinanceInvoiceItem`:

- Fillable `unit`.
- `hasUnitColumn()`.
- `lineTitle()` — trimmed `product_name`, else `description`.
- `displayUnit()` — `unit`, else `unit_code`.

## 5. Service Changes

`CustomerBalanceService`:

- Source of truth: issued sales invoices in the current workspace, `SUM(amount_due)` grouped by `customer_id`.
- `amount_due` already equals `total + amount_debited - amount_credited - amount_paid`.
- Draft and cancelled invoices are excluded via `whereInvoiceStatus('issued')`.

`InvoiceService`:

- Empty `product_id` / `0` becomes `null`.
- If `product_name` is empty, the trimmed description is copied into `product_name` (NOT NULL column).
- A line with quantity/price but no title throws a server-side error.
- Display `unit` is persisted. It is never copied into `unit_code`.

`IssuedSnapshotBuilder::invoiceLine()`:

- Adds optional `unit`. POS snapshot line builders were not touched. `unit_code` guessing was not introduced.

`TaxCalculationService` was not modified.

## 6. UI Changes

Customer create/edit form: party type, VAT, CR, payment terms, free-text address, structured address, country code.

Workspace customers index: type and VAT columns.

Finance customers page: type, VAT, invoice count, **calculated** outstanding, link to the existing customer edit route. Stored `customers.balance` is not shown.

Invoice create:

- Optional product select (`بند حر` when empty).
- Visible item name, unit, description, quantity, unit price, discount, tax classification, tax rate.
- Product selection still fills name/price when chosen.
- Catalog is not required to add a line.

Invoice show: unit column; description uses `lineTitle()`.

## 7. PDF Changes

`workspace.finance.invoices.pdf`:

- First column uses `lineTitle()` so a free-text line such as `عزل أسطح` is visible without a Product.
- New unit column uses `displayUnit()`.
- Quantity, unit price, discount, tax, and line total remain.
- Description is shown under the title only when it differs.
- Branding, snapshots, and the “ZATCA not configured” copy are unchanged.

`PdfInvoiceService` still renders the same Blade view; no PDF layout rewrite.

## 8. Validation Changes

`CustomerPayloadRules::financial()`:

- `party_type` in `individual,company`
- VAT/CR max 32
- Address max 2000
- Structured address fields with existing max lengths
- `country_code` size 2

Invoice store/update:

- Client `total` / `tax_amount` / `taxable_amount` / `subtotal` on lines are discarded.
- `product_id` `0`/empty becomes `null`.
- `unit` is trimmed to 32 characters.
- Quantity, unit price, discount, and tax still validated and calculated by `TaxCalculationService::calculateDocument()`.
- Workspace isolation on `customer_id` / `product_id` is unchanged.

## 9. Permission Changes

No new permission system and no new Spatie permissions.

- Finance pages still go through `FinanceBaseController::authorizeFinance` (Spatie permission **or** owner/admin/manager membership).
- `CustomerPolicy::update` still requires membership in the customer’s workspace. In addition to owner/admin/manager/agent, a member with `customers.manage`, `invoices.create`, or `finance.manage` may update financial customer fields.
- Cross-workspace access is still blocked by `WorkspaceScopedModel` global scope and route-model binding (404).

## 10. Tests Added

`tests/Feature/Feature/Finance/FinancePhaseAFoundationTest.php`:

1. Company customer can store VAT/CR/address (including structured address).
2. Individual customer works without company identifiers.
3. Workspace A cannot get/update Workspace B’s customer; Finance customers page does not leak B.
4. Outstanding uses issued invoices, posted payments, and credit notes — not `customers.balance`.
5. Free-text invoice line works with `product_id = null` and description `عزل أسطح`.
6. Invoice line with `product_id` still works.
7. Unit is persisted (`متر`) and is not written to `unit_code`.
8. Quantity × unit price, discount, and 15% VAT remain server-side (client totals ignored).
9. Free-text invoice PDF HTML contains description, unit, quantity, unit price, and line total.
10. Invoice create form exposes free-text name and unit fields.

## 11. Tests Updated

None. Existing invoice, tax, snapshot, and ZATCA tests were left intact and re-run.

## 12. Test Results

Command:

```text
php vendor/bin/phpunit tests/Feature/Feature/Finance tests/Unit/Finance tests/Unit/EInvoicing tests/Feature/Feature/Workspace/WorkspaceModulesNavigationTest.php --no-coverage
```

| Suite | Result |
| --- | --- |
| Phase A foundation (`FinancePhaseAFoundationTest`) | 8 tests, 76 assertions, passed |
| Finance feature + Finance unit + E-invoicing unit + workspace navigation | 326 tests, 324 passed, 2 skipped, 1 risky, 1 warning, 2669 assertions, ~29s, exit 0 |
| `tests/Feature/Feature/Workspace` | 10 tests, 55 assertions, passed |

Skipped (pre-existing, not Phase A):

- `Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv`
- `Phase7EgsConcurrencyIntegrationTest::test_parallel_workers_allocate_unique_icvs_on_server_database`

Risky (pre-existing):

- `Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables`

Warning (pre-existing OpenSSL parse on a test cert):

- `app/EInvoicing/Security/X509CertificateParser.php:21 openssl_x509_read(): X.509 Certificate cannot be retrieved`

No live browser session was available in this environment (no `.env` / app server). UI and PDF behavior were verified through HTTP feature tests that hit customer store/edit isolation, the invoice create form, and the PDF Blade HTML.

## 13. Any Existing Tests That Failed Before/After

- Before Phase A on this branch: not re-baselined independently; the parent branch already had a green Finance/ZATCA suite.
- After Phase A: **no failing tests** in the suites above.
- The two skipped Phase 7 concurrency tests and the Phase 6 risky test were already special-cased; they are not Phase A regressions.

## 14. Any Risks

- `customers.balance` can still drift. Anything that still reads the column directly would be wrong; Finance UI now uses `CustomerBalanceService`.
- Display `unit` is a human label. ZATCA/UNECE `unit_code` is unchanged and is not inferred from Arabic labels.
- Credit-note / POS line tables do not have `unit`. Quotes are out of scope.
- `CustomerPolicy::create()` remaining `return true` is pre-existing; store still writes through workspace context.
- Invoice create table is wider (extra name/unit columns).

## 15. Any Follow-up Required

Later phases (not this PR):

- Quotes / quote → invoice
- Send invoice, payment links, receipts, reminders
- Optional backfill or deprecation of `customers.balance`
- Display unit on credit notes if those documents need the same line UX
- Flutter Finance

No ZATCA follow-up is required from Phase A.

## 16. Confirmation that these were NOT changed

| Area | Status |
| --- | --- |
| POS | Not changed (POS snapshot line builder untouched) |
| Booking | Not changed |
| Inbox | Not changed |
| WhatsApp | Not changed |
| AI | Not changed |
| Flutter | Not changed |
| ZATCA architecture | Not changed (no XML/QR/CSID/clearance/reporting rewrite; `unit_code` not guessed) |
| Payment engine | Not changed |
| Quotes | Not implemented |

## Success criteria

- [x] Company/Individual customer foundation works
- [x] VAT/CR/address can be managed
- [x] Workspace isolation works
- [x] Customer balance has a clear source of truth (`CustomerBalanceService` / issued `amount_due`)
- [x] Invoice lines support free-text items
- [x] `product_id` remains optional
- [x] Unit is supported
- [x] Quantity is supported
- [x] Unit price is supported
- [x] Discount remains correct
- [x] Existing Tax Engine is reused
- [x] Free-text invoice appears correctly in PDF
- [x] Unit appears in PDF
- [x] Existing Finance tests pass
- [x] Existing ZATCA tests pass
- [x] No POS/Booking/Inbox/AI/Flutter architecture was changed
