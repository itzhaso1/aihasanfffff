# PLAN 3 — Financial + Data Integrity Report

**Date:** 2026-09-18  
**Scope:** Financial correctness, money precision, payments, inventory, idempotency, DB consistency, concurrency  
**Verdict:** **PASS WITH DOCUMENTED ACCOUNTING/RUNTIME RISKS**

---

## 1. FINANCIAL BASELINE

### Commerce / POS path

```text
Order (orders)
  → OrderItem (order_items)     decimal(12,2), Eloquent decimal casts
  → Payment (payments)          decimal amount, status machine
  → (optional) FinanceInvoice link via finance_invoice_id
```

| Metric | Source table | Source service | Storage | Precision | Currency | Rounding |
| --- | --- | --- | --- | --- | --- | --- |
| Line unit price | `order_items.unit_price` | `OrderService`, `PosOrderService` | decimal(12,2) | 2 dp | order.currency | `Money::quantityTimesUnitPrice` (OrderService); POS tax via `PosTaxCalculator` + `Money` |
| Line total | `order_items.total_amount` | same | decimal(12,2) | 2 dp | order.currency | integer cents via `Money` (OrderService) |
| Subtotal | `orders.subtotal` | `OrderService` / POS builders | decimal(12,2) | 2 dp | order.currency | sum of lines |
| Order discount/shipping | `orders.discount_amount`, `shipping_amount` | request payload | decimal(12,2) | 2 dp | order.currency | `Money::sub/add` at total |
| Total | `orders.total_amount` | `OrderService` | decimal(12,2) | 2 dp | order.currency | subtotal − discount + shipping |
| Payment amount | `payments.amount` | `PaymentService` | decimal(12,2) | 2 dp | payment.currency | provider boundary `(float)` for gateway API only |
| Paid/due (commerce) | `orders.payment_status` | webhook settlement | enum | — | — | terminal `paid` blocks duplicate settlement |

### Finance path

```text
FinanceInvoice → FinanceInvoicePayment → FinanceJournalEntry → ledger accounts
Purchase → inventory/COGS (where configured) → journal
Payroll → FinancePayroll* → accounting on paid (PayrollService)
```

| Metric | Source table | Service | Storage | Precision |
| --- | --- | --- | --- | --- |
| Invoice lines/totals | `finance_invoices`, lines | `InvoiceService`, `TaxService` | decimal(14,2) | `Money` minor units in services |
| Payments | `finance_invoice_payments` | `InvoicePaymentService` | decimal(14,2) | `Money` |
| Journal | `finance_journal_entries` | `AccountingService` | decimal(14,2) | balanced debit/credit in minor units |
| P&L / KPI | aggregated | `FinanceAccountingKpiService`, `ReportService` | computed | `Money`; not identical to `expenses.period` tool |

**Semantic note:** POS/commerce totals and Finance invoice totals are **separate domains**. A POS order may link to a finance invoice but they do not share one unified totals engine.

---

## 2. MONEY / PRECISION

### Inventory (Step 2 classification)

| Area | Classification | Notes |
| --- | --- | --- |
| `OrderService` line math (after fix) | **FINANCIAL — FIXED** | Uses `App\Support\Money\Money` for lines/subtotal/total |
| `PosOrderService` tax/totals | **FINANCIAL** | Uses `Money` in tax paths; some display/legacy fields remain decimal |
| `PaymentService` provider call | **PROVIDER/API BOUNDARY** | `(float) $order->total_amount` only at gateway interface |
| Finance services | **FINANCIAL** | `Money` is source of truth |
| Reports / analytics | **DISPLAY-ONLY / MIXED** | Some `round((float)…)` in aggregations — not changed (out of hot-path mutation scope) |

### NF-05 / NF-08 / L-03 — AMBIGUITY (not migrated)

**AMBIGUITY:** Full schema migration of `orders`/`order_items`/`payments` from `decimal(12,2)` to integer minor-unit columns (or widening to `decimal(14,2)` everywhere).

**CURRENT BEHAVIOR:** Values stored as decimal strings; arithmetic in OrderService now uses integer cents via `Money` before persisting.

**RISK:** Historical rows remain decimal; cross-domain reconciliation (commerce decimal vs finance `Money`) still possible at boundaries.

**DATA AFFECTED:** All historical orders/payments.

**SAFE OPTIONS:**
1. Keep decimal storage + `Money` at calculation boundary (current incremental fix).
2. Additive migration: `total_amount_minor` columns + backfill with verification script.
3. Widen to decimal(14,2) only (no semantic change).

**RECOMMENDED OPTION:** (1) now; plan (2) with equivalence proof before production migration.

---

## 3. ORDERS / TOTALS

### Invariant (commerce manual orders)

```text
sum(line.total_amount) − order.discount_amount + order.shipping_amount = order.total_amount
```

Implemented in `OrderService` using `Money::add/sub`. Regression: `OrderFinancialIntegrityTest`.

### Cancel path

`OrderService::cancel` now refreshes customer aggregates under row lock (includes cancelled orders in count — **existing business behavior**).

---

## 4. PAYMENTS

### H-05 — FIXED

**Before:** Provider `createPaymentLink` ran **outside** DB transaction; random UUID idempotency key → concurrent first calls could hit provider twice.

**After (`PaymentService::createPaymentLink`):**
- Single transaction with `Order::lockForUpdate()`
- Provider call **inside** locked transaction (mirrors `createBillablePaymentLink`)
- Deterministic idempotency key: `checkout-order:{workspace_id}:{order_id}`
- Unique constraint `(workspace_id, idempotency_key)` catches races; duplicate key reloads existing row

**Tests:**
- `PaymentIdempotencyTest` (sequential reuse)
- `MysqlProductionGateConcurrencyTest::test_concurrent_payment_link_workers_reuse_single_pending_payment` (MySQL, 2 workers)
- Asserts deterministic idempotency key

### State machine

| Transition | Guard |
| --- | --- |
| → paid (order) | `settleOrderPayment`: order locked; skips if already `paid` |
| → paid (finance invoice) | invoice locked; amount tolerance; idempotent if payment already paid |
| Webhook replay | `WebhookEvent` unique `(workspace_id, provider, external_event_id)` |

Invalid transitions (e.g. paid → pending) not explicitly coded — **AMBIGUITY** for full state diagram; no regression observed in tests.

---

## 5. REFUNDS

Commerce payment refunds are not implemented in `PaymentService` (no refund gateway path in scope).

Finance invoice payment reversal: `InvoicePaymentService` / Finance API `reversePayment` — covered by `FinancialCoreTest` and `FinanceKpiLedgerReconciliationTest`.

**No new refund concurrency defects found in Plan 3 scope.**

---

## 6. INVENTORY

### NF-03 — FIXED

**Before:** `PosOrderService::syncInventoryForPosLine` swallowed unknown `\Throwable` → order could succeed without stock deduction.

**After:** Inventory failures propagate; transaction rolls back with order mutation.

### Policy (actual)

| Category | Behavior |
| --- | --- |
| `inventory_tracking = false` | Skipped (explicit) |
| `inventory_tracking = true` | `InventoryService` throws on insufficient stock; no negative stock |
| POS offline/sync | Uses same `InventoryService`; known exceptions rethrown |

**Tests:** `InventoryServiceTest` (SQLite); `InventoryConcurrencyTest` (MySQL — two workers, one unit stock).

---

## 7. CUSTOMER AGGREGATES

### M-22 — FIXED

**Before:** Read-modify-write recount without customer row lock → lost updates under concurrency.

**After:** `CustomerAggregateService`:
- `incrementOrderAggregates()` — `lockForUpdate` + atomic increment on create (Order + POS)
- `refreshOrderAggregates()` — locked full recount on cancel

**Test:** `CustomerAggregateConcurrencyTest` — 50 concurrent orders, MySQL.

---

## 8. FINANCE IDEMPOTENCY

### NF-09 — PARTIALLY ADDRESSED

| Endpoint / mutation | Retry-safe | Idempotency | Mechanism | Required fix |
| --- | --- | --- | --- | --- |
| `POST /assistant/ask` | Yes | Yes | `IdempotencyService` | — |
| `POST /sales-invoices` | Partial | No middleware | DB + service | Add opt-in idempotency for mobile retries |
| `POST /sales-invoices/{id}/payments` | Partial | No | Service-level | Same |
| `POST /sales-invoices/{id}/checkout` | Partial | Billable payment lock | `createBillablePaymentLink` | — |
| `POST /expenses` | Partial | No | — | Future |
| `POST /payroll/*` | Partial | No | — | Future |
| Commerce `createPaymentLink` | Yes | Yes | Order lock + deterministic key | **Fixed (H-05)** |
| POS order create | Yes | Yes | `client_reference` unique | Existing |

**Recommendation:** Do not apply global idempotency middleware; add per critical POST using same `IdempotencyService` pattern as assistant.

---

## 9. DATABASE INTEGRITY

### Enforced (existing)

- `payments`: unique `(workspace_id, idempotency_key)`, unique `(workspace_id, provider, provider_payment_id)`
- `webhook_events`: unique `(provider, idempotency_key)` / external event id
- `subscription_billing_attempts`: unique provider event
- Workspace-scoped FKs on finance tables

### M-23 — AMBIGUITY

**Column:** `email_campaign_recipients.email_message_id` (nullable, **no FK** in `2026_09_01_180000_hasim_v3_stories_contacts_campaigns.php`).

**CURRENT BEHAVIOR:** Application sets `email_message_id` when campaign send creates message; nullable for pending recipients.

**RISK:** Orphan IDs if messages deleted without cleanup.

**SAFE OPTIONS:**
1. Orphan scan on production + remediate, then additive FK `nullOnDelete`.
2. Leave nullable without FK if messages are soft-deleted only.

**RECOMMENDED OPTION:** Run orphan scan before migration; not executed in this plan (no production data access).

---

## 10. LEDGER RECONCILIATION

Existing suite passes (SQLite critical):

- `FinancialCoreTest` — invoice issue, payment, balanced journal
- `FinanceKpiLedgerReconciliationTest` — sale, credit note, expense, payroll paths
- `ExpenseAccountingIntegrityTest`

No new journal mapping invented.

---

## 11. AI FINANCIAL INTEGRITY

- Finance Agent **not enabled for production** (unchanged).
- Tools use authoritative services (`ReportService`, KPI services) — no client-side arithmetic.
- `ExpensesPeriodTool` description explicitly: *"Expense-module totals by expense_date (not posted P&L operating expenses)."*
- `FinanceAgentGrounding` validates answer numbers against minimized tool results.

---

## 12. HISTORICAL DATA SAFETY

No destructive monetary migration performed.

OrderService now writes the same decimal columns using `Money` rounding — **equivalent for new orders** at 2 dp.

Any future column migration requires pre/post `SUM/MIN/MAX` verification script (not run — no migration applied).

---

## 13. TEST RESULTS

### Financial (SQLite targeted)

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| Financial integrity suite | 5 | 0 | 0 | 2 |

Includes: `MoneyPrecisionTest`, `OrderFinancialIntegrityTest`, `PaymentIdempotencyTest`; MySQL-only tests skipped on SQLite.

### Concurrency (MySQL)

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| CustomerAggregateConcurrencyTest | 1 | 0 | 0 | 0 |
| InventoryConcurrencyTest | 1 | 0 | 0 | 0 |
| MysqlProductionGateConcurrencyTest (payment link) | 1 | 0 | 0 | 0 |

### Critical Regression

| Suite | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| SQLite Critical (`phpunit.critical.xml`) | 412 | 0 | 0 | 2 |
| MySQL Critical (`phpunit.critical.mysql.xml`) | 412 | 0 | 0 | 0 |

### Finance Agent

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| FinanceAgent* filter | 30 | 0 | 0 | 3 |

### Full PHPUnit

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| Full suite | 1033 | 23 | 138 | 19 |

Full-suite failures/errors are predominantly Cashier workspace-context and Plan 2 password mass-assignment side effects — **out of Plan 3 scope**.

---

## 14. FINDINGS UPDATED

| ID | Status | Summary |
| --- | --- | --- |
| **H-05** | **FIXED** | Payment link creation serialized; deterministic idempotency key; provider inside transaction |
| **NF-03** | **FIXED** | POS inventory sync no longer swallows unknown errors |
| **NF-05** | **PARTIALLY_FIXED** | OrderService uses `Money`; schema still decimal(12,2); POS partial |
| **NF-08** | **OPEN** | Finance vs commerce storage precision still dual |
| **NF-09** | **PARTIALLY_FIXED** | Commerce payment idempotency improved; Finance API POSTs mostly without middleware |
| **M-22** | **FIXED** | Customer aggregates use lock + increment/locked refresh |
| **M-23** | **OPEN** | `email_campaign_recipients.email_message_id` FK pending orphan scan |
| **L-03** | **OPEN** | Same family as NF-05; incremental boundary fix only |

### Code changes (Plan 3)

- `app/Services/Payment/PaymentService.php` — H-05
- `app/Services/Order/OrderService.php` — Money totals + aggregates
- `app/Services/Pos/PosOrderService.php` — NF-03 + aggregates
- `app/Services/Customer/CustomerAggregateService.php` — new
- Tests under `tests/Feature/Feature/FinancialIntegrity/`
- Workers: `tests/bin/inventory-order-worker.php`; `order-create-worker.php` supports `customer_id`

---

## 15. REMAINING RISKS

1. **Dual money model (NF-05/NF-08):** Commerce stores decimal; Finance uses `Money` — reconciliation at integration boundaries still requires care.
2. **Finance API retry safety (NF-09):** Most POST mutations lack idempotency keys; mobile retries could duplicate under rare race conditions.
3. **M-23 FK:** Orphan `email_message_id` values possible without FK.
4. **Provider double-mint (residual):** In-process lock prevents duplicate DB rows; external providers without idempotency keys could still see duplicate API calls if lock bypassed (e.g. multi-node without shared DB lock — mitigated by DB row lock on MySQL InnoDB).
5. **Payment state machine:** Not all invalid transitions explicitly rejected.
6. **Full PHPUnit:** 161 failing/error tests outside financial scope remain.

---

## 16. PLAN 3 VERDICT

### **PASS WITH DOCUMENTED ACCOUNTING/RUNTIME RISKS**

**Passed acceptance criteria:**
- No **new** financial calculations using float in OrderService hot path
- Payment double financial effect mitigated under tested concurrency
- No silent POS inventory failure
- Critical commerce payment mutation transaction-safe
- Customer aggregate lost updates fixed (MySQL proof)
- Basic ledger reconciliation tests pass
- No monetary migration without equivalence proof

**Documented risks blocking full PASS:**
- Schema-level money unification (NF-05/NF-08) deferred by design
- Finance API idempotency matrix incomplete (NF-09)
- M-23 FK pending production orphan scan
