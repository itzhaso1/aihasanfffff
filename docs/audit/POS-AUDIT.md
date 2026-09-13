# POS-AUDIT

Audit date: 2026-09-13

---

## 1. Surfaces

| Surface | Path | API |
| --- | --- | --- |
| Blade POS | `app/Http/Controllers/Workspace/Pos/*`, `resources/views/workspace/pos/*` | session |
| Cashier Flutter | `apps/hasim_cashier` | `/api/cashier/v1` |
| Sync aliases | `routes/api.php` `/api/pos/sync` | Sanctum |
| Public QR menu | `/menu/{slug}` | guest |

Shared server logic: `PosOrderService`, `PosCartService`, `PosReturnService`, `PosSyncPushService`, `PosSyncPullService`, `PosTaxCalculator`.

---

## 2. Domain

| Area | Server | Local cashier |
| --- | --- | --- |
| Products / menu | `products` + `pos_menu_items` | catalog repo + Hive/Drift |
| Orders | `orders` / `order_items` | local + push |
| Tables | `dining_tables`, `table_sessions` | table workspace UI |
| Payments | order payments / cashier invoices | payment sheet |
| Discounts | server + client snapshots | cart |
| Refunds / returns | `PosReturnService` | `return_service.dart` |
| Receipts / print | PDF/print services | `printer_service.dart` |
| Inventory | `InventoryService` | `stock_engine.dart` |
| Users / PIN | workspace members | `pin_login_screen`, `pin_hasher` |
| Shifts | **Partial / local** | `shift_service.dart` — platform spec: not first-class ledger |
| Cash drawer | UNKNOWN / local | documented in POS readiness docs |
| Offline | sync operations tables | `sync_engine_v2.dart`, queue, conflicts |
| Backup | none on Laravel | encrypted local backup (`docs/pos-production-readiness.md`) |

---

## 3. Findings

### AUD-POS-001
- Category: POS
- Severity: Critical
- Title: Offline sale + server sale can duplicate
- Location: `PosSyncPushService`, `pos_sync_operations`, Flutter `sync_engine_v2.dart`
- Evidence: Idempotency middleware on cashier group; still a distributed system
- Impact: Double revenue, double stock decrement, customer charged twice
- Recommendation: Unique `client_reference` enforced at DB; reject replay; reconcile report
- Effort: L
- Priority: P0

### AUD-POS-002
- Category: POS
- Severity: Critical
- Title: Local pricing is a second source of truth
- Location: `apps/hasim_cashier/lib/core/pos/domain/pricing_service.dart`
- Evidence: Offline-first POS v2 docs; Flutter money math exists despite platform rule for finance Flutter
- Impact: Ticket total ≠ finance invoice
- Recommendation: Treat local totals as estimates; Laravel recomputes on accept
- Effort: L
- Priority: P0

### AUD-POS-003
- Category: POS
- Severity: High
- Title: Dual catalog (SKU product vs menu item)
- Location: `Product` vs `PosMenuItem` (`product_id` added later)
- Impact: Stock on one, sale on the other
- Recommendation: Single product id on every sold line
- Effort: L
- Priority: P1

### AUD-POS-004
- Category: POS
- Severity: High
- Title: Public menu order injection
- Location: `PosCustomerMenuController`, `routes/web.php`
- Impact: Fake kitchen tickets
- Recommendation: Flag + throttle + optional PIN
- Effort: S
- Priority: P1

### AUD-POS-005
- Category: POS
- Severity: Medium
- Title: Shifts / cash drawer not in Laravel ledger
- Location: spec “Not yet first-class”; Flutter `shift_service.dart`
- Impact: Cash variance invisible to finance P&L
- Recommendation: Post opening/closing as treasury journals
- Effort: L
- Priority: P2

### AUD-POS-006
- Category: POS
- Severity: Medium
- Title: Printer / Windows desktop packaging incomplete
- Location: `printer_service.dart`, Flutter windows runners
- Evidence: Historical POS docs said printing failed in earlier plans; current code exists
- Impact: Restaurant cannot operate
- Recommendation: Device QA matrix
- Effort: M
- Priority: P2

---

## 4. Permissions

Cashier API uses membership + feature + idempotency. Cashiers are **not** finance-elevated (`AuthorizesFinanceApi` comment). Web POS has `PosBaseController` + policies (`OrderPolicy`, `DiningTablePolicy`, `PosCashierInvoicePolicy`).

UNKNOWN — Requires runtime verification: cashier cannot hit `/api/finance/v1` invoice issue; PIN bypass; split tender; offline clock skew.
