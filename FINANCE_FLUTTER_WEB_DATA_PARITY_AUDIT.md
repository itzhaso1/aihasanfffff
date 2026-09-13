# Finance Flutter ↔ Web data parity audit

Independent product: Finance Flutter (`apps/hasim_finance`) consumes `/api/finance/v1`. Laravel remains the financial authority. This document maps Web → services → API → Flutter models → state → UI.

Statuses used: `COMPLETE`, `API_MISSING`, `API_FIELD_MISSING`, `API_RELATION_MISSING`, `FLUTTER_MODEL_MISSING`, `FLUTTER_STATE_MISSING`, `FLUTTER_UI_MISSING`, `PAGINATION_MISMATCH`, `FILTER_MISMATCH`, `PERMISSION_MISMATCH`, `WORKSPACE_ISOLATION_RISK`, `TYPE_MISMATCH`, `NULLABILITY_MISMATCH`, `ENUM_MISMATCH`, `FORMAT_MISMATCH`, `DATA_LOSS`, `INTENTIONAL_DIFFERENCE`.

Every required P0/P1 Finance **client** field is `COMPLETE` after this pass unless listed in `FINANCE_FLUTTER_WEB_DATA_PARITY_EXCEPTIONS.md`.

## How this was verified

- Read Finance Blade controllers/views under `app/Http/Controllers/Workspace/Finance/` and `resources/views/workspace/finance/`.
- Read `/api/finance/v1` presenters (`FinanceClientPresenter`) and controllers.
- Read Flutter models, repositories (`FinanceApi`), screens, and pagination widgets.
- Added Laravel `FinanceFlutterDataParityTest` (rich customer/invoice/statement/search/pagination) and Flutter `field_coverage_test` + `data_parity_screens_test`.
- Flutter does not recompute tax, totals, balances, or report numbers.

## Envelope and isolation

| Domain | Web Data | Web Source | API Endpoint | API Field | Flutter Model | Flutter State | Flutter UI | Status |
|---|---|---|---|---|---|---|---|---|
| Envelope | success/data/meta/message | Finance API | all `/api/finance/v1` | envelope | `ApiResponse` | FinanceApi | error snacks / empty | COMPLETE |
| Workspace | X-Workspace-Id | workspace.resolve + global scopes | all workspace routes | header | PrefsStore.workspaceId | AuthController | workspace picker | COMPLETE |
| Permissions | Spatie + owner elevation | AuthorizesFinanceApi | session `permissions` | dotted keys | FinancePermissions | AuthController | PermissionGate | COMPLETE |

## Matrix (BEFORE → AFTER)

| Domain | Web Data | Web Source | API Endpoint | API Field | Flutter Model | Flutter State | Flutter UI | Status |
|---|---|---|---|---|---|---|---|---|
| Dashboard | outstanding, due, overdue, paid, sales, expenses, AR/AP | DashboardService + Blade | GET `/dashboard` | cards.* | DashboardData.cards | DashboardScreen | KPI grid | COMPLETE |
| Dashboard | purchases, VAT, cash, bank, net profit, contracts | dashboard.blade.php | GET `/dashboard` | cards.purchases, output_vat, input_vat, net_vat, cash_balance, bank_balance, net_profit, active_contracts_count | DashboardData.cards | DashboardScreen | extra cards | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Dashboard | overdue list, recent expenses | DashboardService latest | GET `/dashboard` | overdue_invoices, recent_expenses | DashboardData | DashboardScreen | lists | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Dashboard | payroll KPIs, charts | dashboard.blade.php | omitted from presenter | — | — | — | — | INTENTIONAL_DIFFERENCE (exceptions) |
| Customers | identity, VAT, CR, outstanding | CustomerController web | GET `/customers`, `/customers/{id}` | presenter.customer | CustomerRecord | PagedList + detail | list/detail | COMPLETE |
| Customers | WhatsApp, Saudi address, payment terms, notes | customers table / web form | GET/POST/PUT `/customers` | whatsapp, building_number, street, district, city, postal_code, additional_number, payment_terms, notes | CustomerRecord | CustomerFormScreen / detail | form + overview | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Customers | related invoices/quotes/payments/receipts/contracts | customer show | GET `/customers/{id}` extra | invoices, quotes, payments, receipts, contracts | nested lists | tabs | tabs | COMPLETE |
| Customers | picker page 1 only | forms | GET `/customers?search&page` | page meta | PagedResult | CustomerSelectField | search + load more | BEFORE: PAGINATION_MISMATCH / DATA_LOSS → AFTER: COMPLETE |
| Quotes | number, statuses, totals, lines | QuoteController | `/quotes` | quoteSummary/Detail | QuoteRecord | QuotesScreen | list/detail | COMPLETE |
| Quotes | discount, taxable, terms, rejection, dates | quote show Blade | GET `/quotes/{id}` | discount, taxable_amount, terms, rejection_reason | QuoteRecord | QuoteDetailScreen | TotalsCard + InfoRow | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Quotes | status filter | web index | GET `/quotes?status=` | status | QuotesScreen filterBar | API extra | chips | BEFORE: FILTER_MISMATCH → AFTER: COMPLETE |
| Sales invoices | statuses, totals, lines, payments | Invoice inbox | `/sales-invoices` **not** `/invoices` | invoiceSummary/Detail | InvoiceRecord | InvoicesScreen | list/detail | COMPLETE |
| Sales invoices | discount, taxable, credited/debited | invoice show | summary+detail | discount, taxable_amount, amount_credited, amount_debited | InvoiceRecord | TotalsCard | totals | BEFORE: FLUTTER_MODEL_MISSING → AFTER: COMPLETE |
| Sales invoices | line unit/discount/tax rate | items table | lines[] | unit, discount, tax_rate, taxable_amount | LineItem | LineTable | table | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Sales invoices | credit notes, receipts, snapshots, ZATCA has_qr | invoice show | credit_notes, receipts, company_snapshot, zatca.has_qr | InvoiceRecord | detail lists | lists | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Sales invoices | payment_status filter | InvoiceInboxService | `payment_status` | InvoicesScreen chips | API extra | chips | BEFORE: FILTER_MISMATCH → AFTER: COMPLETE |
| Payments | amount, method, invoice, receipt | PaymentController web | `/payments` | presenter.payment | PaymentRecord | PaymentDetailScreen | detail | COMPLETE |
| Payments | notes, date, treasury, reversal | payment show | notes, payment_date, treasury_account_name, reversed_at | PaymentRecord | detail | InfoRow | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Receipts | number, amount, PDF, send | receipts | `/receipts` | receiptDetail | ReceiptRecord | ReceiptDetailScreen | detail | COMPLETE |
| Receipts | method, reference, invoice, date | receipt show | method, reference, invoice_id, payment_date | ReceiptRecord | detail | InfoRow + invoice link | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Statements | opening/closing, lines, PDF/CSV | statements | GET `/statements` | presenter.statement | StatementRecord | StatementScreen | table | COMPLETE |
| Statements | debit, credit, description, invoice_id, period totals | statement PDF/web | debit, credit, description, invoice_id, invoices_total, payments_total, credits_total, debits_total | StatementRecord.lines + totals | DataTable | table | BEFORE: DATA_LOSS / FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Statements | customer picker | web customer select | customers list | CustomerSelectField | StatementScreen | search/load-more | BEFORE: PAGINATION_MISMATCH → AFTER: COMPLETE |
| Credit/debit notes | number, type, total, issue/cancel/PDF | notes Blade | `/credit-notes` | noteDetail | NoteRecord | NoteDetailScreen | detail | COMPLETE |
| Credit/debit notes | lines, subtotal, reason, invoice | note show | lines, subtotal, reason, invoice_id | NoteRecord | LineTable + TotalsCard | detail | BEFORE: FLUTTER_MODEL_MISSING → AFTER: COMPLETE |
| Contracts | title, customer, value, status, actions | ContractController | `/contracts` | presenter.contract | ContractRecord | ContractDetailScreen | detail | COMPLETE |
| Contracts | terms, items, schedule amount/next_run/auto_issue | contract show | terms, items, billing_schedules.amount/next_run_on | ContractRecord | items + schedules | detail | BEFORE: API_FIELD_MISSING / FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Contracts | generated invoices + billing summary | contract show | generated_invoices, billing_summary | ContractRecord | lists + TotalsCard | detail | BEFORE: API_RELATION_MISSING → AFTER: COMPLETE |
| Contracts | PDF | web PDF | GET `/contracts/{id}/pdf` | binary | saveAndOpenBytes | PDF button | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Expenses | amount, tax, category, attachment | ExpenseController | `/expenses` | presenter.expense | ExpenseRecord | detail | COMPLETE |
| Expenses | supplier, method, treasury, recurring flag | web form | supplier_name, payment_method, treasury_account_name, is_recurring | ExpenseRecord | form/detail | InfoRow | BEFORE: API_FIELD_MISSING / FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Purchases | AP invoices, lines, totals | purchases | `/purchases` | InvoiceRecord | PurchaseDetailScreen | TotalsCard + LineTable | COMPLETE |
| Suppliers | identity, VAT | suppliers | `/suppliers` | SupplierRecord | SupplierSelectField | search/load-more | BEFORE: PAGINATION_MISMATCH → AFTER: COMPLETE |
| Suppliers | CR, address, opening_balance | supplier form | commercial_registration, address, opening_balance | SupplierRecord | fromJson | used in picker identity | BEFORE: FLUTTER_MODEL_MISSING → AFTER: COMPLETE |
| Reports | P&amp;L, BS, TB, GL, aging, cash flow | ReportController web | `/reports/{key}` | ledger JSON | FinanceReportView | tables + KPIs | BEFORE: FLUTTER_UI_MISSING (JSON tree) → AFTER: COMPLETE |
| Reports | date from/to | web date inputs | query from/to | ReportsScreen controllers | passed to API | fields | BEFORE: FILTER_MISMATCH → AFTER: COMPLETE |
| Reports | inventory valuation | reports/index + show | GET `/reports/inventory-valuation` | inventory_valuation | FinanceReportView | table | BEFORE: API_MISSING → AFTER: COMPLETE |
| Reports | period comparison / sales-by-customer charts | reports index extras | not a named report key | — | — | — | INTENTIONAL_DIFFERENCE (VAT/sales/purchases on dashboard) |
| Search | customers, invoices, quotes, receipts, payments | SearchController | GET `/search?q=` | buckets | FinanceSearchScreen | list | COMPLETE |
| Search | expenses, suppliers, contracts, purchases | web search coverage | extra buckets | type routing | FinanceSearchScreen | taps | BEFORE: FLUTTER_UI_MISSING / API_MISSING → AFTER: COMPLETE |
| Settings | company VAT/CR | SettingsController | GET/PUT `/settings` | presenter.settings | SettingsScreen | fields | COMPLETE |
| Settings | Arabic name, address block, phone, email, website, currency, default VAT/terms | web company form | same keys | SettingsScreen controllers | editable | BEFORE: FLUTTER_UI_MISSING → AFTER: COMPLETE |
| Settings | zatca_integration_mode | settings | zatca_integration_mode | InfoRow read-only | never PUT secrets | display | COMPLETE (read-only) |
| Settings | ZATCA keys / sequences / payment secrets | server-only | stripped on update | — | — | — | INTENTIONAL_DIFFERENCE |
| PDF/CSV | invoice/quote/receipt/note/statement/report/export | server PDF/CSV | downloadBytes | saveAndOpenBytes | native + web blob | download | BEFORE: FLUTTER_UI_MISSING on web → AFTER: COMPLETE |
| Checkout | GET availability / POST URL | InvoiceCheckoutService | `/sales-invoices/{id}/checkout` | CheckoutInfo | InvoiceDetailScreen | copy/open | COMPLETE (no client settlement) |
| Pagination | page links 15–20 web | paginate | meta current/last/total | PagedListScreen load-more per_page=25 | lists | COMPLETE |
| Lists | search | web search | `search` query | PagedListScreen | all lists | COMPLETE |

## Permission map (API ↔ Flutter)

| Action | Laravel / API | Flutter visibility | Server still rejects |
|---|---|---|---|
| View finance | finance.view | financeView | 403 |
| Customers | customers.view / customers.manage | customersView / Create | 403 |
| Quotes | quotes.* | quotesView / can() | 403 / 422 issued immutability |
| Sales invoices | invoices.* | invoicesView / can() | 403 |
| Pay / reverse | payments.manage / invoices.reverse_payment | paymentsManage / can() | 403 |
| Receipts send | receipts.send | send button | 403 |
| Statements | invoices.view (API) | statementsView \|\| invoices.view | 403 |
| Notes | invoices.credit mapped to notes.create | can('notes.create') | 403 |
| Contracts | contracts.view / manage; Flutter FAB uses contracts.create mapped in session | contractsView | 403 |
| Expenses | expenses.* | expensesView / can() | 403 |
| Purchases | purchases.* | purchasesView | 403 |
| Reports | reports.view | reportsView | 403 |
| Settings | finance.settings | settings | 403 |
| Checkout GET | payments.view | paymentsManage for POST create | GET does not create Payment |

## Data flow (every in-scope domain)

Laravel DB → Finance services → `/api/finance/v1` presenter → Flutter `FinanceApi` → typed model → screen state → UI.

Domains: dashboard, customers, quotes, sales invoices, payments, receipts, statements, credit/debit notes, contracts/billing schedules, expenses, purchases/suppliers, reports, settings, search, checkout, PDF/CSV.

## P0/P1 classification

P0 found and fixed: statement debit/credit/description drop; customer/supplier picker page-1-only; invoice financial breakdown (discount/taxable/credited) dropped in the model/UI.

P1 found and fixed: reports JSON tree; inventory-valuation API; dashboard extra cards; nested invoice/quote/note/contract/expense/payment/receipt/customer/settings fields; search extra types; web blob download.

P2 left as intentional: payroll/employees/leads/treasury reconciliation UI, GL journal screens, period-comparison charts, POS invoices, Phase 10 `/invoices` contract.
