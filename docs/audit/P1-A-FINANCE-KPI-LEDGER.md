# P1-A FINAL

**Date:** 2026-09-13  
**P0-1 … P0-8:** CLOSED — not reopened.  
**Scope:** Finance dashboard KPI integrity vs posted ledger. No UI redesign. No Finance module rewrite.

## Status

**CLOSED**

Exposed **accounting** KPIs (revenue, expenses, gross/net profit, receivables, payables, ledger cash) now come from `FinanceAccountingKpiService` → `LedgerReportService` (`entry_date`, journals `posted`/`reversed`). Web Blade, Finance API `analytics`, and Flutter (server cards/hero) use that same backend. Drafts do not post. Reversals and credit notes move P&L. Workspace A/B isolation holds. SQLite and isolated MariaDB (`hasem_p0_tenancy_test`) executed. Production database `hasem` was not used.

---

## KPI Inventory

| KPI | Current formula | Canonical source | Difference | Status |
| --- | --- | --- | --- | --- |
| Hero sales / `كم بعنا؟` | Ledger P&L **revenue** (account type `revenue`, natural credit) | `LedgerReportService::profitAndLoss` | Was `SUM(finance_invoices.total)` incl. tax on `issue_date` | **A Ledger — aligned** |
| Hero profit / `كم ربحنا؟` | Ledger **net_profit** | same P&L | Was taxable sales − purchase taxable − expense docs | **A Ledger — aligned** |
| Hero receivables | Account **1200** natural balance as of `to` | `accountNaturalBalance('1200')` | Was `SUM(amount_due)` of invoices issued in the period | **A Ledger — aligned** |
| Hero payables | Account **2000** as of `to` | `accountNaturalBalance('2000')` | Was period purchase `amount_due` | **A Ledger — aligned** |
| Secondary expenses | P&L operating + other expenses (excludes COGS) | P&L | Was `SUM(finance_expenses.total)` | **A Ledger — aligned** |
| Secondary cash | Ledger cash flow **closing** (codes 1000/1100) | `cashFlow()` | Was `SUM(treasury.current_balance)` | **A Ledger — aligned** |
| Blade / API `ledger_profit` | Same P&L object | P&L | API previously **stripped** it | **A — API now exposes** |
| Blade / API `cash_flow` | Opening / net / closing | `cashFlow()` | API previously **stripped** it | **A — API now exposes** |
| Period comparison revenue / expenses / gross / net / receivables | Same accounting service | `FinanceAccountingKpiService::period` | Was invoice `taxable_amount` / expense docs | **A Ledger — aligned** |
| API/Flutter `cards.sales` / `expenses` / `net_profit` / AR / AP | Lifetime ledger (`1970-01-01` … today) | same service | Was all-time invoice/expense SUM (tax-inclusive sales) | **A Ledger — aligned** |
| Overdue amount / counts | Issued sales + `payment_status` | Invoice rows | Operational | **B Operational** |
| Inventory value | `cost_price × stock` | Products | Operational (same idea as inventory report) | **B Operational** |
| VAT cards `output_vat` / `input_vat` / `net_vat` | Invoice/expense `tax_amount` | Document tax | VAT is balance-sheet (2100/1400), not P&L. Not treated as revenue | **B Operational — classified** |
| Flutter `cards.purchases` | `SUM(issued purchase.total)` | Invoice rows | Operational purchases, not COGS | **B Operational** |
| Treasury `cash_balance` / `bank_balance` | Treasury `current_balance` by type | Treasury cache | Distinct from ledger 1000/1100 | **B Operational** |
| Period comparison cash/bank | Treasury split | Treasury | Point-in-time, not windowed | **B Operational** |
| Monthly series / `$charts` profit | Invoice/expense by month; some `round(float)` | Documents | Labeled تقريبي on Blade | **C Hybrid / operational chart** |
| Project profit widget | Sales taxable − project expenses | Documents | No purchase invoices | **C Hybrid** |
| Payroll / contracts / employees | HR tables | Operational | Unchanged | **B Operational** |

**Canonical period basis:** `finance_journal_entries.entry_date` inclusive (`whereDate >= from` and `<= to`). Invoice posting writes `entry_date = issue_date`.

---

## Findings Before Fix

- Hero “إيراد” was **gross invoice total including VAT**, while P&L revenue is **taxable** (credit 4000). Proven: 100 + 15% tax → ledger revenue `100.00`.
- Hero profit ignored journals; a still-issued cancelled/reversed invoice could keep counting if status columns lagged.
- Receivables were a **period cohort** of `amount_due`, not AR 1200.
- DashboardService used **floats** and `round(..., 2)` for accounting-like cards.
- Finance API presenter omitted `cash_flow` and `ledger_profit` (Blade had them; Flutter could not).
- Period comparison “المبيعات” was taxable invoice SUM, not P&L.
- No dedicated reconciliation test.

---

## Source of Truth

**Accounting KPIs:** posted + reversed journal lines, scoped by `workspace_id`, dated by **`entry_date`**.

**Operational KPIs:** invoice/expense/treasury/product rows (overdue, VAT cards, purchases card, inventory, payroll, chart series).

Flutter does **not** recompute accounting totals; it displays server `analytics.hero` and `cards.*`.

---

## Changes

- `LedgerReportService::accountNaturalBalance()` for 1200 / 2000 as-of a date.
- `FinanceAccountingKpiService::period()` — single extraction used by analytics, period comparison, and dashboard cards.
- `FinanceAnalyticsService` hero/secondary accounting values from that service; reuse P&L/cash in the payload (no second query pair).
- `PeriodComparisonService` revenue / expenses / gross / net / receivables from the ledger.
- `DashboardService` cards sales / expenses / net_profit / receivables / payables = lifetime ledger via `Money` (no float profit math).
- `FinanceClientPresenter::analytics()` now includes `cash_flow` and `ledger_profit`.
- Blade ledger-profit hint: same P&L source (no redesign).
- Tests: `FinanceKpiLedgerReconciliationTest`; frontend/billing assertions updated from tax-inclusive totals to ledger revenue.

Not changed: invoice posting, tax engine, COA, Flutter local math (none for totals), P0 tenancy.

---

## Reconciliation Examples

Executed on SQLite and MariaDB (workspace A, current month unless noted):

**Revenue:** issued 10,000 zero-rated sale → hero / P&L / API `cards.sales` = `10000.00`. Draft 5,000 → unchanged. Credit note 2,000 → `8000.00` while invoice `taxable_amount` stays `10000.00`. Cancel/reverse → `0.00`.

**Expenses:** approved expense 4,000 → P&L expenses `4000.00`. Manual journal debit 5900 / credit 1000 of 100.00 → expenses `100.00`.

**Net profit:** 10,000 − 4,000 = `6000.00`. After 100 adjustment = `9900.00` (revenue still 10,000).

**Tax:** 100 + 15% VAT → ledger revenue `100.00`; `cards.output_vat` = `15.00` (operational, not added to revenue).

**Other:** AR 1200 after 10,000 sale = `10000.00`; after 2,500 payment = `7500.00`. Date window 2026-01-01…2026-01-31 includes 100+30, excludes 31 Dec and 1 Feb. Workspace B 333/111 does not enter A.

---

## Web / API / Flutter

| Surface | Accounting path |
| --- | --- |
| Blade dashboard | `FinanceAnalyticsService::dashboard()` hero/secondary + `ledger_profit` / `cash_flow` |
| `GET /api/finance/v1/dashboard` | Same service via presenter; `analytics.hero` + `analytics.ledger_profit` + `analytics.cash_flow` + lifetime `cards.*` |
| Flutter `hasim_finance` | Displays those JSON strings; no local P&L |

Same `from`/`to` → web service hero = API `analytics.hero` = API `cards` when the only activity is inside that window (proven in test).

---

## SQLite

Command: `php artisan test --testdox --filter=FinanceKpiLedgerReconciliationTest`

| | |
| --- | --- |
| **PASS** | 12 tests, 80 assertions |
| **FAIL** | 0 |
| **SKIPPED** | 0 |
| **ERRORS** | 0 |

Related updates: `FinanceFrontendExperienceTest` hero = `1000.00` (= P&L). `FinanceBillingParityImplementationTest::test_dashboard_sales_total_*` expects `200.00` ledger revenue (not `230.00` tax-inclusive).

Broader finance filter (existing class names; there is **no** `FinanceAnalytics` / `LedgerReport` / `PeriodComparison` / `CustomerBalance` test class):

`FinancialCoreTest` + `ExpenseAccountingIntegrityTest` + `PlatformCoreTest` + frontend/billing/P1-A: **PASS**.

`InvoiceDomainHardeningTest` and several `FinanceInvoiceCheckoutTest` methods **error in isolation** with `MissingWorkspaceContextException` on `FinanceInvoiceItem` (InvoiceService `fresh(['items'])` / test relation load). They fail the same way when run **alone**, without P1-A files. **Not introduced by this change.** Not silently skipped — recorded here. P0 tenancy was not reopened to paper over those tests.

---

## MariaDB/MySQL

Probe: `php tests/bin/mysql-p0-probe.php` → `status=READY`, `test_database=hasem_p0_tenancy_test`, `version=10.4.32-MariaDB`, `configured_database=hasem` (**not used**).

Config: `phpunit.mysql.xml` → `DB_DATABASE=hasem_p0_tenancy_test`.

| | |
| --- | --- |
| **PASS** | 14 tests, 102 assertions (`FinanceKpiLedgerReconciliationTest` + dashboard-related frontend/billing) |
| **FAIL** | 0 |
| **SKIPPED** | 0 |
| **ERRORS** | 0 |

PHPUnit printed a runner warning (`--configuration` passed twice via `artisan test`). Tests themselves passed.

---

## Remaining Divergences

Documented, not hidden:

1. **VAT cards** remain operational document tax. They are not P&L revenue and are not claimed as 2100/1400 trial-balance unless a dedicated tax report is used.
2. **`cards.purchases`** remains issued purchase invoice totals (operational).
3. **Monthly series / DashboardService `$charts`** remain document-based and still use float `round` in the 12-month chart helper. Blade labels the profit chart تقريبي.
4. **Period comparison cash/bank** remain treasury point-in-time.
5. **Customer/product/lifecycle filters** on the dashboard do **not** reshape posted P&L (journals are not customer-sliced on this surface).
6. **Lifetime vs period:** API `cards.sales` is lifetime ledger; `analytics.hero` is the requested `from`/`to`. Same numbers only when all postings fall in that window.

No unexplained accounting-KPI vs P&L gap on the hero/secondary/ledger cards.
