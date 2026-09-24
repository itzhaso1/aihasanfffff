# HASEM Master Platform Specification

This document describes the **current** HASEM architecture after the platform-core expansion. It is a living spec for the existing Laravel monolith, not a proposal to split the product into separate apps.

HASEM remains one application: shared database, `workspace_id` tenancy, Blade finance UI, REST/cashier/mobile APIs, queues, and scheduled jobs.

---

## 1. Architecture

### Style

Modular Laravel monolith.

| Layer | Responsibility |
| --- | --- |
| Presentation | Blade finance workspace UI, cashier/POS, REST/mobile/cashier APIs |
| Application | Controllers, form requests, workspace middleware, feature gates |
| Domain services | Accounting, invoices, inventory, POS, CRM, AI, billing |
| Infrastructure | Eloquent + `WorkspaceScopedModel`, Redis/cache, queues, storage, webhooks, payment adapters |

### Non-negotiable rules

1. Ledger is the source of truth for posted financial effects. Do not patch balances by hand.
2. Monetary arithmetic uses integer minor units via `App\Support\Money\Money` (2 decimal places). Do not use raw floats for money math.
3. Posted journal entries are immutable. Corrections use reversal entries.
4. Closed accounting periods and fiscal years reject posting.
5. Every tenant-owned query must be workspace-scoped. Cross-workspace access must 404.
6. AI may read authorized workspace data and suggest actions. It must not post, refund, or mutate finance records without a confirmed application workflow.
7. Existing APIs, migrations, and invoice statuses stay backward compatible unless a dedicated migration exists.

### Tenancy model

- Canonical tenant: `workspaces` (`individual`, `company`, `store`).
- Membership: `workspace_users` with membership roles (`owner`, `admin`, `manager`, `agent`, …).
- Isolation: `workspace_id` on tenant tables + `WorkspaceScopedModel` global scope + membership middleware + Spatie permission teams.
- Feature flags: `workspace_feature_flags` plus plan entitlements.

### Identity and authorization

- User auth: Laravel session (web) + Sanctum (API).
- Platform admin: isolated guard/panel.
- RBAC: Spatie roles/permissions, team-aware by workspace.
- Finance UI: `FinanceBaseController::authorizeFinance` — Spatie permission **or** membership `owner/admin/manager`.
- Server-side authorization is required. Hiding a nav link is not access control.

---

## 2. Domain map (inside one product)

Official rule: **one platform, one website, one Laravel backend, one database, three functional domains, three catalogs.** Catalogs do not inherit from each other.

```text
HASEM PLATFORM
├── Identity / Tenancy / RBAC / Customers (shared)
├── FINANCE DOMAIN
│   ├── Finance / Commerce Catalog (`products`)
│   ├── Invoices / Accounting / Expenses / Taxes / Treasury / Reports
│   └── `/api/finance/v1/*` + apps/hasim_finance
├── CASHIER / POS DOMAIN
│   ├── Cashier Catalog (`pos_menu_items` + `pos_item_categories`)
│   ├── POS sales / orders / tables / kitchen / Electronic Menu
│   └── `/api/cashier/v1/*` + apps/hasim_cashier
├── CHAT DOMAIN
│   ├── Chat Catalog (`chat_catalog_items` + categories/variants)
│   ├── Unified Inbox / WhatsApp / Email / AI (Chat Catalog + role + business context)
│   └── `/api/mobile/v1/*` + apps/hasim
├── Billing / Subscriptions / Plans (unchanged; not a catalog)
└── Infrastructure (queues, scheduler, observability)
```

### Catalog isolation

| Catalog | Source of truth | Consumers | Does not feed |
| --- | --- | --- | --- |
| Finance / Commerce | `products` | Finance UI/API, inventory, optional invoice lines | Cashier, Chat AI, Electronic Menu |
| Cashier | `pos_menu_items` | POS, cashier API, Electronic Menu (active only) | Finance catalog, Chat AI |
| Chat | `chat_catalog_items` | Chat app, Chat Catalog API, Chat AI | Finance, Cashier, Electronic Menu |

`pos_menu_items.product_id` is an **optional inventory mapping**, not catalog inheritance. Creating a Finance product does not create a POS or Chat item, and the reverse is also false.

Chat AI retrieval searches Chat Catalog tokens only. Instagram / Messenger / Telegram UI may exist; WhatsApp inbound is the live inbox channel. Do not document UI-only channels as live backends.

P1-17 is **domain catalog separation**, not “unify all products”.

---

## 3. Chart of accounts

Bootstrapped per workspace by `ChartOfAccountsService`. System accounts (codes are stable):

| Code | Name | Type | Notes |
| --- | --- | --- | --- |
| 1000 | Cash | asset | Linked to Main Cash treasury |
| 1100 | Bank | asset | Linked to Main Bank treasury |
| 1200 | Accounts Receivable | asset | Sales invoices |
| 1210 | Employee Advances Receivable | asset | Salary advances |
| 1300 | Inventory | asset | Tracked product purchases/COGS |
| 1400 | Input VAT Receivable | asset | Purchase VAT |
| 2000 | Accounts Payable | liability | Purchase invoices |
| 2100 | Output VAT Payable | liability | Sales VAT |
| 2200 | Payroll Payable | liability | Payroll |
| 3000 | Capital | equity | |
| 3100 | Retained Earnings | equity | |
| 4000 | Sales Revenue | revenue | Sales invoices |
| 4100 | Service Revenue | revenue | |
| 4200 | Foreign Exchange Gain | revenue | Reserved for FX (not auto-posted yet) |
| 5000 | Salaries Expense | expense | |
| 5100 | Rent Expense | expense | |
| 5200 | Utilities Expense | expense | |
| 5300 | Cost of Goods Sold | expense | Inventory sales |
| 5400 | Foreign Exchange Loss | expense | Reserved for FX |
| 5900 | General Expense | expense | Non-inventory purchases and expenses |

Accounts are workspace-owned, coded, typed, classified, active/inactive, and marked `is_system` when seeded.

---

## 4. Double-entry accounting

**Service:** `AccountingService`

- Journals are created as `draft`, lines inserted, then status set to `posted` in the same transaction.
- Totals compared with `Money::cmp`. Unbalanced entries throw.
- Zero-amount lines are rejected.
- Account IDs are re-loaded inside the workspace (IDOR-safe).
- `FinancialPeriodGuardService` blocks posting into `status=closed` periods or years.
- `FinanceJournalEntry` refuses silent mutation of posted/reversed rows except marking `posted → reversed`.
- `FinanceJournalEntryLine` refuses mutation; lines can only be added while the parent is `draft`.
- `reverseEntry()` posts a balancing opposite journal and marks the original `reversed`. Description of the original is not rewritten.

### Accounting impact by business event

| Event | Typical entry |
| --- | --- |
| Sales invoice issued | Dr 1200 AR; Cr 4000 Revenue; Cr 2100 VAT; if tracked stock: Dr 5300 COGS / Cr 1300 Inventory |
| Sales payment | Dr 1000/1100 Cash/Bank; Cr 1200 AR |
| Purchase invoice (services / untracked) | Dr 5900 Expense; Dr 1400 Input VAT; Cr 2000 AP |
| Purchase invoice (tracked products) | Dr 1300 Inventory (taxable); Dr 1400 VAT; Cr 2000 AP |
| Expense posted | Dr expense account; Cr cash/AP per payment account |
| Expense reversal / invoice cancel | Reversal journal; stock reverse when applicable |
| Treasury transfer | Dr destination linked account; Cr source linked account + treasury balance adjust |
| Credit note / refund | Existing credit-note and payment-reversal services |

Purchase invoices **without** inventory-tracked products keep debiting `5900`. Do not “fix” that path.

When a purchase order already received stock, the supplier bill is created with `skip_inventory=true` so stock is not double-counted.

---

## 5. Money

`App\Support\Money\Money`:

- Stores/calculates in integer cents.
- `of`, `add`, `sub`, `mul`, `cmp`, `isZero`, `isPositive`.
- `mul` is amount × amount at 2 dp (not a general quantity engine). Invoice quantities remain decimal(12,3); stock adjustments round to integers.

Database columns remain `decimal(14,2)` (or existing schema). The helper prevents float drift in PHP.

---

## 6. Modules, entities, workflows

### CRM / customers

**Existing:** `Customer`, contacts/tags/notes/groups, customer statements, aging via invoices.

**Added:** `crm_leads` + `LeadService`.

Lead workflow: `new` → convert to `Customer` (phone fallback `n/a` because `customers.phone` is NOT NULL) or `lost`.

Customer statement UI already existed (`workspace.finance.statements.*`).

### Sales document flow

Current production path is **invoice-centric**, not a full CRM quote → order pipeline.

| Stage | Status |
| --- | --- |
| Lead | Implemented (`crm_leads`) |
| Quotation | Not a first-class document yet |
| Sales order | Not a first-class document yet (POS/cashier orders exist separately) |
| Invoice | Full lifecycle: draft / issued / cancelled + payment unpaid / partial / paid / overdue |
| Payment | Partial, multiple, reverse; treasury + AR |
| Credit notes / refunds | Existing finance credit-note module |

### POS

Existing cashier/POS module (products, barcode/SKU search, tables, invoices, returns, cashier API). This phase did **not** rewrite POS. Inventory sales from finance invoices now post COGS; POS should continue using `InventoryService` and invoice posting paths already in the cashier domain.

Not yet first-class in this phase: cashier shifts, opening/closing cash as a dedicated shift ledger.

### Products / inventory

**Existing:** products, variants, SKU/barcode, integer `stock`, `inventory_tracking`, `InventoryService`, movements.

**Added:** `InventoryAccountingService` — COGS + inventory GL + stock in/out on issued finance invoices; insufficient stock aborts the invoice transaction.

**Not yet:** multi-warehouse, locations, FIFO/weighted-average lots, serials/batches/expiry as first-class tables, kits.

### Purchasing

**Existing:** suppliers, purchase invoices.

**Added:** `finance_purchase_orders` + items.

Workflow: `draft` → `sent` → `partial_received` / `received` (stock add) → `billed` (purchase invoice). Unique `po_number` per workspace.

### Expenses

Existing expense module with posting and reversal. Deleting posted expenses must reverse, not erase. `project_id` optional for profitability.

### Treasury / bank reconciliation

**Existing:** `finance_treasury_accounts`, banks UI.

**Added:**

- Transfers: `finance_treasury_transfers`, balanced JE, row locks, reference idempotency + unique `(workspace_id, reference)`.
- Statements: `finance_bank_statements` / `_lines`.
- `BankReconciliationService`: manual lines, amount-based suggestions, match, complete.
- Suggestions never auto-post journals.

CSV/OFX/bank API imports are architecture-ready (statement line table) but not implemented.

### Projects

`finance_projects`. Profit = issued sales `taxable_amount` − non-draft/cancelled expenses on `project_id`.

### Employees / payroll / billing

Unchanged existing modules. This phase did not replace them.

### Reporting

`LedgerReportService` (posted + reversed journals by date — reversal appears in the reversal period):

- Trial balance (debit == credit flag)
- Profit & loss (revenue, COGS, opex, other, net)
- Balance sheet including current earnings
- General ledger
- AR / AP aging
- Cash flow approximation from 1000/1100 movement
- Inventory valuation (`cost_price * stock` for tracked products)

`PeriodComparisonService` powers dashboard windows: today, this week, this month, last month, this year, previous year.

Dashboard KPI cards may still show a simplified sales − purchases − expenses view. **Ledger P&L is the accounting source of truth.**

### Alerts

`BusinessAlertService`: overdue invoices, unpaid supplier bills, low stock, unusual discounts, unbalanced trial balance (when journals exist).

### AI

| Capability | Implementation | Guardrails |
| --- | --- | --- |
| Financial copilot | `FinanceCopilotService` — intent match over ledger/invoices/expenses/stock | Workspace-scoped; numbers from SQL aggregates; date range returned |
| Create-invoice NL | Preview only (`requires_confirmation`) | Does **not** insert invoices |
| Workspace assistant | Existing `AssistantController` / AI service | No unrestricted SQL; no secrets |
| OCR / anomaly ML / full forecasting models | Not implemented | Forecast copilot answer is a labeled projection from current AR/AP/cash, not a statistical model |
| Bank match suggestions | Deterministic amount matching | User must confirm match |

### Search

`FinanceSearchService`: customers, invoices, expenses, products, suppliers, leads. Workspace + `finance.view`. Soft-deleted leads/projects excluded via model defaults.

### Compliance / tax / FX

- Tax engine: existing `TaxService` + `finance_tax_rates` (inclusive/exclusive handled in invoice tax logic).
- Country profiles (`Support/Compliance`): SA VAT 15% (ZATCA), JO GST 16% (ISTD FAQ), AE VAT 5% (FTA). Extra rates `firstOrCreate`d at bootstrap. Default new workspace country remains SA.
- Do not invent JoFotara / ZATCA e-invoicing claims. Those integrations are not in this phase.
- Multi-currency: invoice `currency` exists; historical FX gain/loss auto-posting is **not** implemented (accounts 4200/5400 reserved).

### Localization

Arabic-first finance UI, RTL layout. Business logic is not duplicated per language.

---

## 7. Database design (this phase)

New additive migration: `database/migrations/2026_09_02_220000_add_platform_financial_core_tables.php`

| Table | Purpose | Isolation / integrity |
| --- | --- | --- |
| `finance_treasury_transfers` | Cash/bank transfers | `workspace_id`, unique `(workspace_id, reference)` |
| `finance_bank_statements` | Bank statement header | `workspace_id` |
| `finance_bank_statement_lines` | Statement lines + match fields | `workspace_id` |
| `finance_purchase_orders` | PO header | unique `(workspace_id, po_number)`, soft deletes |
| `finance_purchase_order_items` | PO lines | `workspace_id` |
| `crm_leads` | Leads | `workspace_id`, soft deletes |
| `finance_projects` | Projects | `workspace_id`, soft deletes |

Additive columns:

- `finance_invoices.project_id` (nullable FK)
- `finance_expenses.project_id` (nullable FK)
- indexes: journal lines `(workspace_id, account_id)`, products `(workspace_id, inventory_tracking, stock)`

No dropped tables. No rewritten historical migrations.

---

## 8. API design

This phase is **web/finance UI first**. No new public REST version was added for POs/leads/copilot.

Existing APIs remain:

- `/api` workspace products, orders, inventory, payments, AI
- Cashier `/api/cashier/v1/*`
- Mobile `/api/mobile/v1/*`

Finance resources continue to use session web routes under `/w/{workspace}/finance/...` (prefix as implemented in `routes/web.php`).

Idempotency:

- Treasury transfer `reference` unique per workspace
- Invoice payment uniqueness / locks as previously implemented
- PO number unique per workspace

---

## 9. AI architecture

```text
User question
 → finance.view authorization
 → workspace_id from current workspace only
 → intent detection (AR/EN keywords)
 → deterministic services (LedgerReportService, invoices, alerts)
 → answer + optional range + optional preview_action
```

Forbidden:

- Unrestricted SQL for the LLM
- Reading other workspaces
- Auto-posting journals, invoices, refunds, stock
- Inventing amounts when no rows exist (copilot returns `0.00`)

Future AI actions must follow: intent → permission → validation → preview → user confirm → transactional execute → audit.

---

## 10. Security model

| Control | Mechanism |
| --- | --- |
| Authentication | Session / Sanctum |
| Authorization | Spatie permissions + membership fallback in finance UI |
| Tenancy | Global scopes + `assertSameWorkspace` + explicit `workspace_id` filters |
| IDOR | Route model binding under scoped models; lead convert uses integer id + workspace filter |
| Mass assignment | `#[Fillable]` / guarded attributes |
| Money integrity | `Money` + balanced journals |
| Period lock | `FinancialPeriodGuardService` |
| Audit | Existing `AuditLog` on sensitive finance mutations; posted journals not silently edited |
| Secrets | Env-based; AI prompts must not echo credentials |
| File uploads | Existing invoice attachment validation |

Granular permission catalog (existing + finance): `finance.view`, `finance.manage`, `invoices.*`, `accounting.*`, `journal.view/create/reverse`, `inventory.manage/adjust`, plus domain permissions in `FoundationSeeder`. Owner receives all permissions via seeder pluck. Finer keys such as `invoice.refund` / `expense.approve` are **not** fully split yet; several screens still use `finance.manage` / `invoices.create`.

---

## 11. Event model

No new domain-event bus was mandated for every write. Current pattern:

- Synchronous services inside `DB::transaction`
- Existing notification/webhook infrastructure for selected billing/WhatsApp/payment events

Recommended future events (when decoupling is needed): `InvoiceIssued`, `InvoicePaid`, `PaymentReceived`, `InventoryAdjusted`, `ExpenseApproved`, `TreasuryTransferred`. Listeners: accounting (already inline), notifications, analytics, AI indexing.

Do not force events onto paths that are already transactional and correct.

---

## 12. Testing strategy

| Suite | Covers |
| --- | --- |
| `tests/Unit/Money/MoneyTest.php` | Integer money add/mul/validation |
| `tests/Feature/Feature/Finance/PlatformCoreTest.php` | COGS/stock, purchase inventory vs expense, journal immutability, TB/P&L, transfer idempotency + isolation, copilot isolation, search/lead IDOR, PO bill, insufficient stock, AI no-create, statement/project/PO IDOR, closed period journals |
| Existing `FinancialCoreTest`, `ExpenseAccountingIntegrityTest`, `BillingLifecycleTest` | Invoices, payments, expenses, periods, payroll |
| Existing tenancy/security/POS tests | Isolation, RBAC, cashier |

Adversarial cases required for finance: other-workspace IDs, closed periods, duplicate transfer references, insufficient stock, AI create intent without execute.

---

## 13. Deployment requirements

- PHP 8.3+ with `bcmath`, `mbstring`, `xml`, `intl` recommended, `sqlite`/`mysql` pdo
- Composer install, `APP_KEY`, migrate **forward only**
- Queue worker for mail/webhooks/AI (existing)
- Scheduler for billing reminders / overdue (existing; financial jobs must stay idempotent)
- Do not reset production databases
- After deploy: run finance + platform test filters in CI

Backups, restore drills, and on-call runbooks: see `docs/operations/production-runbook.md` and `docs/deployment.md`. This phase does not claim a newly tested disaster-recovery restore.

---

## 14. Permissions (finance UI)

Typical checks:

| Area | Permission |
| --- | --- |
| Dashboard, reports, alerts, copilot, search, projects list | `finance.view` |
| Leads, projects create, POs (store/submit/receive) | `finance.manage` |
| PO bill | `invoices.create` |
| Transfers, bank statements | `accounting.manage` |
| Invoices | `invoices.view` / `invoices.create` / issue / cancel / reverse payment |

---

## 15. What this spec is not

It is not a claim that quotations, warehouses, FIFO, OCR, JoFotara, configurable multi-step approval matrices, timesheets, or statistical forecasting are production-complete. Those remain sequenced follow-on work on the same monolith.
