# P0-2 — Server-authoritative POS tax + idempotent offline sync

**Date:** 2026-09-13  
**P0-1:** CLOSED — not reopened. Tenancy model unchanged.

**FINAL STATUS: P0-2 CLOSED**

---

## 1. Findings before the fix (audit)

### F1 — Client tax/total could become ledger truth

`PosOrderService::resolveOfflineSaleFinancials()` treated offline snapshots as accounting facts:

- payload `tax_amount` stored when present
- else sum of client line `tax_amount`
- else tax derived from client `total_amount`
- a consistent lie (tax=0 + matching total) was accepted

`normalizePosItems()` copied `items.*.tax_amount`.  
`PosTaxCalculator::attachLineTax()` kept client line tax when any line sent `tax_amount`.  
`createOrderWithSnapshots()` persisted caller-supplied tax/total/subtotal.

**Proof:** `CashierPhase2AOfflineSnapshotTest::test_snapshot_discount_tax_and_total_remain_historical` expected client tax `0.90` instead of workspace 15%.

### F2 — Two tax engines

| Engine | Path | Rate | Risk |
| --- | --- | --- | --- |
| `TaxCalculationService` | Finance invoices | finance tax rates | official |
| `PosTaxCalculator` | POS / cashier invoices | `settings.pos.tax_rate` | own math + trusted client lines |

Flutter `PricingService` is a third calculator (display only).

### F3 — Sync UUID existed; concurrent apply was not locked

`pos_sync_operations` already unique on `(workspace_id, operation_uuid)`.

`processOne()` used `first()` without `lockForUpdate`. A `syncing` row was dispatched again. Same UUID + different payload was acked, not rejected.

`orders` unique `(workspace_id, client_reference)` existed, but `createPosOrder` did not catch the unique violation.

### F4 — Invoice / payment / stock

- `createInvoiceFromOrder()` checked `pos_cashier_invoice_id` **outside** the row lock.
- `closeSession()` returned `null` if already closed (no canonical invoice on retry).
- Sale stock is deducted in `order.created` via `adjustStock` (not idempotent by reference). Duplicate order ⇒ duplicate stock.
- `stock.movement` kinds `sale`/`remove` already skipped.
- Cashier cash sets `payment_status` only (no `payments` row).

### F5 — Flutter

- Local pricing computed tax/total and sent them in the sync payload.
- Outbox `operationUuid` is a UUID at enqueue (retry / restart reuse the SQLite row).
- Fallback id could become `clientReference:entity:op:{localIntId}`.
- `_finalizeOrderCreate` / `_finalizeInvoice` did not persist canonical server tax/total.

### F6 — Already OK

- Unique `(workspace_id, operation_uuid)` and `(workspace_id, client_reference)`
- Sequential duplicate-push tests
- Web cart checkout sends menu item + qty only
- Sale stock.movement skipped

---

## 2. Files changed

### Laravel

- `app/Services/Pos/PosTaxCalculator.php` — adapter over `TaxCalculationService`; never keeps client line tax
- `app/Services/Pos/PosOrderService.php` — server-only totals; unique `client_reference` catch; invoice/session lock
- `app/Services/Pos/PosSyncPushService.php` — transaction + `lockForUpdate` + payload fingerprint + 409 conflict; canonical totals in ack

### Flutter Cashier

- `apps/hasim_cashier/lib/core/pos/domain/pricing_service.dart` — display-only comment
- `apps/hasim_cashier/lib/core/sync/sync_engine_v2.dart` — persist missing UUID; store server tax/subtotal/total on sync success

### Tests / helpers

- `tests/Feature/Feature/Pos/PosServerAuthoritativeTaxAndIdempotencyTest.php` (new)
- `tests/Feature/Feature/Cashier/CashierPhase2AOfflineSnapshotTest.php` — expect server tax `1.20` / total `9.20`
- `tests/TestCase.php` — `placePosOrder()` refreshes relations inside `runFor`

### Report

- `docs/audit/P0-2-POS-TAX-IDEMPOTENT-SYNC.md`

---

## 3. DB migrations added

**None.** Existing unique keys are reused:

| Key | Constraint | Scope |
| --- | --- | --- |
| Sync operation | `pos_sync_ops_ws_uuid_unique` | **`workspace_id` + `operation_uuid`** |
| Sale identity | `orders_workspace_client_reference_uidx` | **`workspace_id` + `client_reference`** |

Not a global UUID. The same UUID in another workspace is a different operation.

---

## 4. Canonical tax engine

**Official calculator:** `App\Services\Finance\Tax\TaxCalculationService`

POS uses it through `PosTaxCalculator`:

- `taxAmount()` → `calculateAmount($taxable, 'standard', $rate)`
- `attachLineTax()` allocates header tax; **ignores client line tax**
- Rounding: `App\Support\Money\Money` (integer minor units)

**Rate policy (unchanged product behavior):** `workspace.settings.pos.tax_rate`. If unset/0, POS tax is 0 (does not silently apply finance 15% VAT). Finance invoices still use finance rates.

---

## 5. Idempotency design

Every Cashier sale mutation must send `operations[].id` (UUID).

| Case | Behavior |
| --- | --- |
| First UUID | claim row (`syncing`) → apply → `accepted` + canonical result |
| Same UUID + same payload | return original entity / result (`duplicate`) |
| Same UUID + different payload | **409 reject**, no mutation |
| New UUID | new sale |
| Retry after timeout / restart | same stored UUID → original sale |

Order `client_reference` unique per workspace is a second safety net (`UniqueConstraintViolationException` → return existing).

---

## 6. Concurrency strategy

1. Unique `(workspace_id, operation_uuid)`
2. `DB::transaction` + `lockForUpdate()` on the operation row
3. Loser of the insert race loads the locked row
4. If already `accepted` or `entity_id` set → canonical ack
5. Else apply once

Invoice: re-check `pos_cashier_invoice_id` after `lockForUpdate`.  
Session close: lock session; if already closed, return existing cashier invoice.

PHPUnit on Windows cannot fork true parallel HTTP. The suite proves unique+lock + sequential overlap (second request after first accepted) and duplicate side-effect counts.

---

## 7. SQLite test result

Engine: SQLite `:memory:` (`phpunit.xml`)

```text
php artisan test --testdox tests/Feature/Feature/Pos/PosServerAuthoritativeTaxAndIdempotencyTest.php tests/Unit/Finance/TaxEngineTest.php
OK (16 tests, 117 assertions)

php artisan test --testdox tests/Feature/Feature/Pos/PosFinalCashierCompletionTest.php --filter="test_tax_is_calculated_from_workspace_settings_in_backend|test_discount_percent_is_applied_server_side"
OK (2 tests, 6 assertions)
```

| Suite | Tests | Assertions | FAIL | SKIP | ERROR |
| --- | --- | --- | --- | --- | --- |
| P0-2 + TaxEngine | 16 | 117 | 0 | 0 | 0 |
| POS tax/discount | 2 | 6 | 0 | 0 | 0 |
| **SQLite relevant total** | **18** | **123** | **0** | **0** | **0** |

Covered: tax recomputation, manipulated totals, duplicate sync, UUID conflict, duplicate payment_status/invoice, duplicate stock, sequential concurrent UUID.

---

## 8. MySQL/MariaDB test result

- Engine: **MariaDB 10.4.32**
- Isolated DB: **`hasem_p0_tenancy_test`** (not production `hasem`)
- Separate test database: **yes**

```text
php vendor/bin/phpunit -c phpunit.mysql.xml --testdox tests/Feature/Feature/Pos/PosServerAuthoritativeTaxAndIdempotencyTest.php tests/Unit/Finance/TaxEngineTest.php
OK (16 tests, 117 assertions)

php vendor/bin/phpunit -c phpunit.mysql.xml --testdox tests/Feature/Feature/Pos/PosFinalCashierCompletionTest.php --filter="test_tax_is_calculated|test_discount_percent"
OK (2 tests, 6 assertions)
```

| Suite | Tests | Assertions | FAIL | SKIP | ERROR |
| --- | --- | --- | --- | --- | --- |
| P0-2 + TaxEngine | 16 | 117 | 0 | 0 | 0 |
| POS tax/discount | 2 | 6 | 0 | 0 | 0 |
| **MariaDB relevant total** | **18** | **123** | **0** | **0** | **0** |

---

## 9. PASS / FAIL / SKIPPED / ERROR counts

**Relevant P0-2 (SQLite + MariaDB, same cases):**

- PASS: 18 + 18 = **36 test runs**
- FAIL: **0**
- SKIPPED: **0**
- ERRORS: **0**

---

## 10. Remaining REVIEW items

1. **POS rate source** is still `settings.pos.tax_rate`, not `finance_settings.default_vat_rate`. Math is one engine; rate tables are still two. Changing default POS VAT to finance 15% would alter existing stores with no POS rate.
2. Older cashier feature tests still call `Order::query()` after the HTTP request without workspace context (fail-closed). That is a P0-1 test-style leftover, not a production sync bug. New P0-2 tests use `withoutGlobalScopes()`.
3. Concurrency test is lock/unique serialized, not OS-level parallel workers.
4. Flutter still computes local tax for offline UI. Server ignores it.
5. Cashier cash still has no `payments` row; “one payment” means one `payment_status=paid` + one cashier invoice.
6. `PosReturnService` still accepts optional client `amount` on returns (outside sale-create scope).
7. No new migration; uniqueness is workspace-scoped, not global.

---

## Closure checklist

| Criterion | Result |
| --- | --- |
| Server tax authoritative | PASS |
| Client tax/total cannot alter truth | PASS |
| One authoritative tax calculator | PASS (`TaxCalculationService`) |
| Offline retry idempotent | PASS |
| Concurrent duplicate prevented | PASS (unique + lock) |
| Payment/stock side effects cannot duplicate | PASS |
| SQLite relevant tests PASS | PASS (18 / 0 / 0 / 0) |
| Isolated MariaDB tests PASS | PASS (18 / 0 / 0 / 0) |

**FINAL STATUS = P0-2 CLOSED**

P1 was not started.
