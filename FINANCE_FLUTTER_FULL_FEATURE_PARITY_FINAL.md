# Finance Flutter — full feature parity final matrix

Re-audit after gap closure: Web reconnaissance → additive Laravel `/api/finance/v1` → Flutter screens/forms/actions → tests → visual QA.

Laravel remains the financial authority. Flutter (`apps/hasim_finance`) is a full functional Finance client on `/api/finance/v1`. Sales invoices use `/sales-invoices`, never Phase 10 `/invoices`.

**Allowed statuses only:** COMPLETE | WEB-ONLY (strong justification) | NOT-FINANCE

| Module | Web | API | Flutter Model | Flutter Screen | Create | Edit | View | Actions | Filters | Export | Permission | Status |
|--------|-----|-----|---------------|----------------|--------|------|------|---------|---------|--------|------------|--------|
| Decision dashboard | Yes | GET `/dashboard` | DashboardData | `/dashboard` | n/a | n/a | Yes | Refresh, apply, reset | from/to, customer, product, project, lifecycle, payment method | n/a | finance.view | COMPLETE |
| Billing hub | Yes | GET `/billing` | map | `/billing` | n/a | n/a | Yes | Refresh | from/to | n/a | invoices.view | COMPLETE |
| Sales hub | Yes | GET `/sales` | map | `/sales` | n/a | n/a | Yes | Refresh | from/to | n/a | finance.view | COMPLETE |
| Customers | List + forms | `/customers` | CustomerRecord | list/detail/form | Yes | Yes | Yes | Open related docs | search, pagination | n/a | customers.* | COMPLETE |
| Customer statements | Yes | GET `/statements` | StatementRecord | `/statements` | n/a | n/a | Yes | PDF, CSV | customer, from/to | PDF/CSV | statements.view | COMPLETE |
| Quotes | Full lifecycle | `/quotes` | QuoteRecord | list/detail/form | Yes | Draft | Yes | issue, send, accept, reject, convert, cancel, delete draft, PDF | status, outcome, search | PDF | quotes.* | COMPLETE |
| Quote file attachments | No file input; multipart enctype leftover; `QuoteService` never stores files | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | COMPLETE — Web has no quote-file feature to port |
| Sales invoices | Full builder | `/sales-invoices` | InvoiceRecord | list/detail/form | Yes | Draft | Yes | issue, send, remind, cancel, delete draft, payment, reverse, checkout, notes, attachments, PDF, audit | lifecycle, search | PDF | invoices.* | COMPLETE |
| Invoice lines | Web builder | items[] | LineItem / LineDraft | line editor | Yes | Draft | Yes | add/remove, product picker | n/a | n/a | invoices.edit | COMPLETE |
| Credit/debit notes | Nested | `/credit-notes` | NoteRecord | `/notes` | Yes | n/a | Yes | issue, cancel, PDF | search | PDF | notes.* | COMPLETE |
| Payments | Index | `/payments` | PaymentRecord | list/detail | via invoice | n/a | Yes | reverse | search | n/a | payments.* | COMPLETE |
| Receipts | Index/show | `/receipts` | ReceiptRecord | list/detail | via payment | n/a | Yes | send, PDF | search | PDF | receipts.* | COMPLETE |
| Reminders | Invoice action | POST remind | n/a | invoice detail | n/a | n/a | n/a | remind | n/a | n/a | invoices.remind | COMPLETE |
| Checkout | Invoice action | GET/POST checkout | CheckoutInfo | invoice detail | n/a | n/a | URL | copy/open/refresh; **no client paid** | n/a | n/a | payments.manage | COMPLETE |
| Contracts | Full | `/contracts` | ContractRecord | list/detail/form | Yes | Draft | Yes | activate, close, cancel, PDF, schedules, generate, **file attachments** | status, search | PDF | contracts.* | COMPLETE |
| Contract file attachments | create/update multipart + show download/destroy | POST/GET/DELETE `/contracts/{id}/attachments` via `ContractService` | attachments[] | contract detail + create picker | Yes | Yes (not closed/cancelled) | Yes | upload, list, download, delete, confirm, refresh | n/a | file | contracts.view / manage | COMPLETE |
| Billing schedules | Yes | schedule routes | BillingScheduleRecord | contract detail | Yes | n/a | Yes | activate, pause, cancel, generate | n/a | n/a | contracts.manage | COMPLETE |
| Expenses | Yes | `/expenses` | ExpenseRecord | list/detail/form | Yes | Draft | Yes | attachment, delete draft | status, search | attachment | expenses.* | COMPLETE |
| Purchases (AP) | Yes | `/purchases` | InvoiceRecord | list/detail/form | Yes | Draft | Yes | issue, cancel, PDF | lifecycle, search, supplier | PDF | purchases.* | COMPLETE |
| Purchase orders | Yes | `/purchase-orders` | PurchaseOrderRecord | list/detail/form | Yes | n/a | Yes | submit, receive, bill | status, search | n/a | purchases.* | COMPLETE |
| Suppliers | Index forms | `/suppliers` | SupplierRecord | list/detail/form | Yes | Yes | Yes | n/a | search, pagination | n/a | purchases.view | COMPLETE |
| Products (Finance read) | List + sold totals | `/products` | ProductRecord | list/detail | No | No | Yes | n/a | search | n/a | finance.view | COMPLETE for Finance view |
| Product CMS | Products product | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | products | NOT-FINANCE |
| Inventory movements | List | `/inventory` | InventoryMovementRecord | `/inventory` | n/a | n/a | Yes | n/a | search | n/a | finance.view | COMPLETE for Finance list |
| Inventory ops / POS stock | POS/Products | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | POS | NOT-FINANCE |
| Projects | Index/create | `/projects` | ProjectRecord | list/detail/form | Yes | n/a | Yes | n/a | search | n/a | finance.view | COMPLETE |
| Price lists | Full | `/price-lists` | PriceListRecord | list/detail/form | Yes | Yes | Yes | items, approve, draft, cancel | search | n/a | finance.price_lists.* | COMPLETE |
| Leads | Index/convert | `/leads` | LeadRecord | list/detail/form | Yes | n/a | Yes | convert, lost | status, search | n/a | finance.view | COMPLETE |
| Reports (P&L, TB, CF, BS, GL, AR/AP aging, inventory valuation) | Yes | `/reports/*` | report JSON | `/reports` | n/a | n/a | Yes | run | from/to, account | CSV | reports.view | COMPLETE |
| VAT hub | Yes | GET `/vat` | map | `/vat` | n/a | n/a | Yes | Refresh | n/a | n/a | accounting.view | COMPLETE |
| Accounting hub (read) | Accounts, journals, trial balance, monthly cash flow — **no create/edit** | GET `/accounting` | map | `/accounting` | n/a | n/a | Yes | Refresh | n/a | n/a | accounting.view | COMPLETE |
| GL journal create / COA editors | **Not provided by Web** (`AccountingController` is read-only) | none | n/a | n/a | No | No | n/a | n/a | n/a | n/a | n/a | WEB-ONLY — Web itself has no editor; inventing posting APIs would duplicate the ledger |
| Fiscal years | Yes | `/fiscal-years` | FiscalYearRecord | list/detail | Yes | Yes | Yes | open/close year, periods | n/a | n/a | finance.fiscal_years.* | COMPLETE |
| CSV exports | Yes | `/exports` | map | `/exports` | n/a | n/a | Yes | download datasets | n/a | CSV | finance.view | COMPLETE |
| Alerts | Yes | GET `/alerts` | list | `/alerts` | n/a | n/a | Yes | Refresh | n/a | n/a | finance.view | COMPLETE |
| Copilot | Yes | POST `/copilot/ask` | map | `/copilot` | n/a | n/a | Yes | ask | n/a | n/a | finance.view | COMPLETE |
| Banks | Yes | GET `/banks` | TreasuryAccountRecord | `/banks` | via settings | n/a | Yes | n/a | pagination | n/a | finance.view | COMPLETE |
| Treasury transfers | Yes | GET/POST treasury | map | `/treasury` | transfer | n/a | Yes | transfer | n/a | n/a | finance.view / accounting.manage | COMPLETE |
| Bank statement matching | Import, lines, suggest, accept, complete | `/treasury/statements*` wrapping `BankReconciliationService` | map | `/treasury` + `/treasury/statements/:id` | Yes | lines | Yes | add lines, suggest, accept, ignore (service), complete | n/a | n/a | finance.view / accounting.manage | COMPLETE |
| Bank matched-line undo | Not in Web UI or service | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | COMPLETE — no Web/service undo to port. Matching does not post new ledger entries. |
| Settings company | Yes | GET/PUT `/settings` | map | `/settings` | n/a | Yes | Yes | save company, tax rates list/create (default/active), treasury cash/bank create with balances and linked ledger account | n/a | n/a | finance.settings | COMPLETE |
| Settings logo | logo + remove_logo | GET/POST/DELETE `/settings/logo` | has_logo / bytes | `/settings` | Yes | replace | preview | choose, upload, replace, remove | n/a | n/a | finance.settings | COMPLETE |
| ZATCA secrets / keys / stamp UI | Server | stripped | n/a | read-only mode | No | No | Mode | n/a | n/a | n/a | server | WEB-ONLY |
| Search | Yes | GET `/search` | buckets | `/search` | n/a | n/a | Yes | navigate | q | n/a | finance.view | COMPLETE |
| Audit (invoice) | Invoice show | presenter `audit` | maps | invoice detail | n/a | n/a | Yes | n/a | n/a | n/a | invoices.view | COMPLETE |
| Auth / workspaces | Yes | auth + `/workspaces` | AuthState | login, Google, forgot, picker | n/a | n/a | Yes | switch, logout | n/a | n/a | Sanctum | COMPLETE |
| Navigation | Sidebar groups | n/a | `_navGroups` | FinanceShell | n/a | n/a | Yes | grouped RTL | n/a | n/a | per module | COMPLETE (payroll omitted) |
| PDF invoice/quote/receipt/note/contract/purchase/statement | Yes | `*/pdf` | bytes | details | n/a | n/a | Yes | open/share | n/a | PDF | matching view | COMPLETE |
| Payroll block | Yes in Web sidebar | none for client | n/a | n/a | No | No | No | n/a | n/a | n/a | payroll.* | NOT-FINANCE |
| POS / Booking / Inbox | Other products | n/a | n/a | n/a | No | No | No | n/a | n/a | n/a | other | NOT-FINANCE |
| Phase 10 `/invoices` | Compliance API | exists, unused by Flutter | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | e-invoice | WEB-ONLY / separate contract |
| Cashbox placeholder | Placeholder | none | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | n/a | WEB-ONLY (not a real feature) |
| Recurring expense engine | `is_recurring` boolean only | flag | flag | form switch | flag | flag | Yes | n/a | n/a | n/a | expenses | COMPLETE for what Laravel stores. No scheduler exists on Web or API. |
| Dashboard Blade charts | Decorative | analytics series JSON | analytics.series | lists/KPIs | n/a | n/a | Yes | n/a | period | n/a | finance.view | COMPLETE for numbers; chart chrome is WEB-ONLY presentation |

## Re-opened WEB-ONLY decisions

### Quote create-time attachments — not a Web feature
Web quote create uses `enctype="multipart/form-data"` because it shares the document-builder form shell. There is no `type="file"` input. `QuoteController` / `QuoteService` never read or store uploaded files. Issued quotes remain immutable. Flutter matches Web: no quote-file engine was invented.

### Bank statement matching — implemented
Web treasury is genuine Finance: create statement, add lines, suggest matches, accept suggestion, complete. `BankReconciliationService` records links; it does **not** post new ledger entries. Flutter now calls that service through `/api/finance/v1/treasury/statements*`. Ignore-line is in the service (required before complete when a line cannot match) and is exposed in Flutter; Web Blade omitted the button. Matched-line undo does not exist in the service or Web.

### General ledger / chart of accounts — Web is read-only
`AccountingController::dashboard` lists accounts, journal entries, trial balance, and monthly cash flow. There is no journal create/edit and no COA editor. Flutter `/accounting` now shows the same read surfaces. Inventing a posting API would not match Web and would risk a second accounting engine.

### Company logo — implemented
Web `SettingsController::updateCompany` stores `logo` on the public disk and can `remove_logo`, skipping delete when an issued/cancelled invoice snapshot still references the path. Flutter uses GET/POST/DELETE `/settings/logo`. Binary storage stays on Laravel.

## Tests

| Check | Result |
|---|---|
| `flutter analyze` (`apps/hasim_finance`) | No issues |
| `flutter test` | 68 passed |
| `FinanceFlutterFeatureParityTest` (hubs, catalog, attachments, logo, dashboard filters, bank matching, settings tax/treasury lists, workspace isolation) | passed |
| Additional Finance Flutter / checkout / billing PHPUnit | passed |
| Pint | dirty files formatted |

Live authenticated visual QA is recorded after this revision against a local Finance workspace.

## Product boundaries respected

- No POS/Cashier, Booking, Inbox, WhatsApp, Instagram, Messenger, or generic AI shell.
- No dummy Orders.
- No second tax/accounting/auth engine.
- No local ZATCA key management.
- No client-side payment settlement or authoritative totals.
- Payroll remains HR, not Finance Flutter.
