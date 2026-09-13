# HASEM Implementation Status

Status values: **Existing** (unchanged this phase), **Strengthened**, **New**, **Partial**, **Not started**.

This file reflects the platform-core increment plus the finance frontend ERP phase (`cursor/finance-frontend-erp-b600`). It does not claim the entire 54-module mission is complete.

---

## Feature matrix

| Feature | Status | Existing / New | Files changed | Database | API | Tests | Security | Accounting impact | Remaining work |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Money helper | New | New | `app/Support/Money/Money.php` | — | — | `tests/Unit/Money/MoneyTest.php` | Prevents float drift | Used by journal/tax/treasury | Quantity×money with >2 dp still rounds via invoice/stock rules |
| Chart of accounts | Strengthened | Existing + codes 4200/5300/5400 | `ChartOfAccountsService.php` | Seeded accounts, no new table | — | PlatformCoreTest COGS lines | Workspace `byCode($code, $workspaceId)` | COGS + FX placeholder accounts | Parent-account tree UI not added |
| Double-entry ledger | Strengthened | Existing | `AccountingService.php`, journal models | — | — | Immutability + TB balanced | Period guard; posted rows locked | Draft-then-post; reverse status | Dedicated `journal.post` UI workflow still coarse |
| Period closing | Existing | Existing | `FinancialPeriodGuardService.php` | `finance_accounting_periods.status` | — | PlatformCoreTest + FinancialCoreTest | Closed period blocks posting | Blocks journals/invoices/transfers | Correction-only override role not added |
| Customers / statements | Existing | Existing | Statement controllers (untouched) | — | Web | Prior finance tests | Workspace scope | AR from invoices | Credit-limit enforcement not hard-blocked |
| Leads | New | New | `LeadService`, `LeadController`, `CrmLead` | `crm_leads` | Web routes | Search + convert IDOR | Integer id + workspace filter | None until converted | Pipeline/stages/deals not full CRM |
| Invoices / payments / credit notes | Strengthened | Existing | `InvoiceService.php` | `project_id` nullable | Existing web | PlatformCore + FinancialCore | Authz unchanged | Inventory/COGS lines; `skip_inventory` | Quotations/sales orders still missing |
| Inventory accounting | New | New | `InventoryAccountingService.php` | Product stock index | — | COGS, purchase inventory, insufficient stock | Stock abort inside invoice TX | Dr/Cr 5300/1300; purchases Dr 1300 vs 5900 | Warehouses, FIFO, serials, batches |
| Purchase orders | New | New | `PurchaseOrderService`, controller, models | PO tables | Web | PO→bill balanced; cross-WS bill 404 | Supplier scoped to workspace | Bill uses InvoiceService; skip stock if received | Purchase requests, approvals, supplier returns |
| Treasury transfers | New | New | `TreasuryTransferService` | `finance_treasury_transfers` + unique ref | Web | Idempotent + isolated | Row locks; unique ref | Balanced cash/bank JE | Insufficient-funds policy optional |
| Bank reconciliation | New | New | `BankReconciliationService`, treasury UI | statements + lines | Web | Statement IDOR 404 | Suggestions never post | Match only; no auto JE | CSV/OFX, bank APIs |
| Ledger reports | New | New | `LedgerReportService`, report views | Journal line index | Web `reports/{report}` | TB/P&L after sale | `finance.view` | Includes posted+reversed by date | Export, comparative statements, cash-flow IAS 7 |
| Executive dashboard compare | Strengthened | Existing + New | `PeriodComparisonService`, dashboard view | — | Web | Dashboard load in tests | Workspace only | Cards still simplified vs ledger P&L | Align KPI cards with ledger P&L |
| Business alerts | New | New | `BusinessAlertService`, alerts view | — | Web | Indirect via copilot attention | `finance.view` | Flags unbalanced TB | Anomaly ML, refund/discount policy engine |
| Finance copilot | New | New | `FinanceCopilotService`, IntelligenceController | — | Web POST copilot | Grounded isolation; no create | Workspace SQL only | Read-only | Confirmation-gated writes, OCR, forecasting models |
| Global finance search | New | New | `FinanceSearchService` | — | Web + JSON wantsJson | Cross-WS lead hidden | Permission + workspace | — | Projects in search; tighter permission per type |
| Projects | New | New | `ProjectService`, `FinanceProject` | `finance_projects` + FKs | Web | List isolation | Customer must belong to WS | Profit from invoice/expense sums | Tasks, time, billing from timesheets |
| Compliance profiles | New | New | `app/Support/Compliance/*`, bootstrap | Extra tax rate rows | — | Indirect bootstrap | Not in controllers | Default rates only | JoFotara, ZATCA XML, full GST schedules |
| POS | Existing | Existing | Unchanged this phase | — | Cashier API | Existing POS tests | Existing | Sales via existing invoice/POS paths | Shifts, split tender UX audit |
| Employees / payroll | Existing | Existing | Unchanged | — | Web | FinancialCore payroll | Existing | Existing payroll JEs | Commissions from payments |
| Subscriptions / SaaS billing | Existing | Existing | Unchanged | — | Existing | BillingLifecycleTest | Existing | Existing | — |
| Multi-currency FX | Partial | Accounts only | COA 4200/5400 | — | — | — | — | No auto FX JE | Historical rates, revaluation |
| Tax engine | Existing | Existing | `TaxService` uses Money | tax rates | — | FinancialCore tax | Configurable rates | VAT lines 2100/1400 | Country-specific e-invoice |
| Approval workflows | Partial | Existing expense/invoice statuses | — | — | — | — | Threshold matrix not generic | — | Configurable multi-step approvals |
| Audit log | Existing | Existing | Journal immutability | — | — | Immutability test | Posted JE cannot be edited | Reversal trail | Immutable audit store |
| Webhooks / API platform | Existing | Existing | Unchanged | — | Existing APIs | Existing API tests | Existing | — | Finance REST version + new event names |
| Notifications | Existing | Existing | Unchanged | — | — | — | — | — | Alert → in-app/email fanout |
| Documents / attachments | Existing | Invoice attachments | Unchanged | — | — | Prior upload tests | Existing MIME/size | — | Attachments on PO/expense/project |
| Performance | Partial | Indexes + sqlite DATE_FORMAT fix | journal lines, products | Indexes | — | Dashboard sqlite | — | Aggregate reports | Report caching, N+1 pass on all Blade |
| Concurrency | Partial | Transfer unique + invoice TX + stock | unique transfer ref | Unique | — | Transfer idempotency | UniqueConstraint catch | — | Parallel PHP workers for stock/payment races |
| Localization | Existing | Arabic finance UI | sidebar/search/copilot views | — | — | — | — | — | EN parity for new screens |
| Security hardening | Strengthened | Isolation tests expanded | controllers assertSameWorkspace | — | — | IDOR tests | Cross-tenant 404 | — | Split `finance.manage` into finer abilities |
| OCR / anomaly ML / timesheets / warehouses / quotations | Not started | — | — | — | — | — | — | — | Later phases B–F |

---

## Frontend ERP phase (invoices, contracts, decision analytics)

| Feature | Status | Notes |
| --- | --- | --- |
| Invoice inbox | Strengthened | Draft / Sent / Partial / Paid / Overdue / Cancelled as **display** lifecycle; stored statuses unchanged. Mobile cards, tax + due columns, contract/project/payment-method filters. |
| Invoice document | Strengthened | Collection progress bar, tax/discount stack, e-invoice readiness checklist (no gateway send). |
| Contracts | Strengthened | Workspace stats, expiry alerts (30 days), project link, invoiced vs collected, customer/project/expiring filters. |
| Decision dashboard | New | Answers: كم بعنا؟ كم ربحنا؟ كم لنا؟ كم علينا؟ + trends vs previous period, cash-flow from ledger 1000/1100, ledger P&L card, period comparison table, top/overdue customers, products, project profit, expense mix, inventory, expiring contracts. Filters: date, customer, product, project, lifecycle, payment method. |
| Reports hub | Strengthened | Ledger P&L + cash flow + period comparison + product performance; report tiles instead of a flat link row. |
| Contract → invoice | Strengthened | Prefill customer/contract/project from the contract show page. |
| Export | Not started | Filter architecture is ready; CSV/PDF export is later. |

## Phase A (previous increment) — financial core paths

- Integer money math in accounting/tax/treasury
- Posted journal immutability + reversal status
- Inventory ⇄ ledger (COGS, inventory asset, skip double-count on PO bill)
- Purchase order lifecycle
- Treasury transfer + bank statement matching
- Ledger reports + period comparison + alerts
- Grounded copilot + finance search
- Leads + projects
- Country tax profile objects (SA/JO/AE)
- Additive migration only

## Later phases (not claimed done)

- **B:** Warehouses, FIFO/serials, POS shifts, purchase requests/returns
- **C:** Full report exports, cash-flow statement quality, dashboard/ledger unification
- **D:** Full CRM pipelines, timesheets, commissions
- **E:** Already largely exists for SaaS billing; keep hardening idempotent invoice generation
- **F:** OCR, confirmation-gated AI writes, statistical forecasts, anomaly jobs
- **G:** Versioned finance API + webhook events for new entities
- **H:** Official e-invoicing (only with current official docs)
- **I:** Load tests, restore drills, finer RBAC

---

## Tests added this phase

- `tests/Unit/Money/MoneyTest.php`
- `tests/Feature/Feature/Finance/PlatformCoreTest.php`

Run (local):

```bash
export PATH="$HOME/.local/bin:$PATH"
php artisan test --filter='MoneyTest|PlatformCoreTest|FinancialCoreTest|ExpenseAccountingIntegrityTest'
```

`phpunit.xml` includes a testing `APP_KEY` so the suite can boot.

---

## Production blockers / honesty

1. Dashboard metric cards are still a simplified formula; use ledger P&L for accounting close.
2. Copilot is keyword intent, not an LLM with tool-calling. It will not answer arbitrary questions.
3. Unique transfer reference is the idempotency key; blank references are not idempotent.
4. Stock is integer; invoice qty is decimal — quantities round at the inventory boundary.
5. Do not treat compliance profiles as a filed tax return or e-invoicing certification.
