# PLAN 5 — Performance + Scalability Report

**Date:** 2026-09-18  
**Codebase:** `D:\projects\aihasan`  
**Scope:** DB query performance, N+1, unbounded loading, API payload, pagination, bootstrap, reporting, scheduler, cache, cashier polling/sync, AI tool bounds, indexing.

---

## 1. BASELINE

Hot paths were inventoried across controllers, services, jobs, schedulers, bootstrap endpoints, cashier/finance/mobile APIs, and AI assistant tools. Common risk patterns found:

| Pattern | Examples found |
| --- | --- |
| Full collection load + PHP aggregate | Cashier daily report (pre-fix), finance dashboards (partial) |
| Correlated subquery per row | Inbox unread filter (pre-fix) |
| Fixed 5s polling | Cashier `PollingPosEventSource` (pre-fix) |
| Bootstrap caps without `has_more` | Finance bootstrap (pre-fix) |
| Platform-wide scheduler scan | Hourly invoice refresh (pre-fix) |
| Uncached entitlements recompute | `FeatureAccessService::enabledFeatureSet` (pre-fix) |
| OR-of-LIKE catalog search | Chat catalog retrieval (bounded post-fix) |

**Baseline matrix (selected hot paths):**

| Feature | Query count (est.) | Peak rows loaded | Payload | Cache | Index | Risk (before) |
| --- | --- | --- | --- | --- | --- | --- |
| Cashier daily report | 5–10+ full order loads | All day orders + items | Very large JSON | None | partial | **High** |
| Finance bootstrap | 8–12 | 200–500 rows/entity | Large | None | workspace_id | **Medium** |
| Inbox unread count | 1 with correlated subquery | N conversations scanned | Small | None | partial | **Medium** |
| Entitlements snapshot | 4–8 per request | Plan + addons | Medium | None | N/A | **Medium** |
| Hourly invoice refresh | Platform chunk | All issued invoices hourly | N/A | None | partial | **High** |
| Cashier sync push | 1 HTTP / 50 ops | 50 ops/request | Medium | N/A | N/A | **Medium** |
| Chat catalog search | 1 LIKE query | up to 40 items | Small | None | name/sku | **Medium** |
| CRM customer timeline | 4+ queries | 4×ceil(limit/4) | Medium | None | FK indexes | **Medium** |

No production p50/p95 metrics were available; measurements below are **SQLite test/local** unless noted.

---

## 2. HOT PATH INVENTORY

**Addressed this plan:** Cashier daily report, finance bootstrap meta, inbox unread, entitlements cache, invoice scheduler partitioning, table list eager load, CRM timeline caps, chat catalog bounds, performance indexes, cashier polling adaptive intervals, sync chunking verification.

**Documented / deferred:** Full finance dashboard SQL profiling at 100K rows, MySQL EXPLAIN at scale, Reverb/WebSocket cashier path, campaign recipient batch preload refactor, WorkspaceAddon cache invalidation.

---

## 3. CASHIER POLLING (H-13)

### Before
- Fixed **5s** polling in `PollingPosEventSource` for menu/tables feeds.
- ~**12 requests/min/device** regardless of activity.

### After
- **Adaptive polling** in `apps/hasim_cashier/lib/core/realtime/pos_event_source.dart`:
  - Active interval: 5s (unchanged for UX on active screens).
  - After **6 empty ticks**, backs off to `idleInterval` (30s configured).
  - Timer reschedules dynamically instead of fixed `Timer.periodic`.
- `AppConfig`: `menuPollIdleSeconds = 30`, `tablesPollIdleSeconds = 30`, `autoSyncSeconds = 15` (was 5).
- Wired in `menu_orders_feed.dart`, `tables_board.dart`.
- Widget tests updated (`auto_sync_kitchen_test.dart`, `cashier_gaps_test.dart`).

### Evidence
- Idle device load drops from ~12 req/min to ~**2 req/min** when no POS events (product/UX acceptable).
- Active kitchen/tables screens still poll at 5s until idle backoff triggers.

### Product decision recorded
- **WebSocket/Reverb path** remains future work; polling is still the production transport.
- Further interval increases on **active** screens need explicit UX sign-off.

---

## 4. CASHIER SYNC (H-14)

### Architecture (verified)
```
Client offline queue (SQLite)
  → sync_engine_v2.dart loops chunks of 50
  → POST /sync/push (max 50 ops/server)
  → idempotency keys preserved
  → resume on failure (remaining queue retried)
```

### Before
- Single-request cap 50; concern was sending 500+ ops in one HTTP body.

### After
- **No server contract change required:** client already chunks at `chunkSize = 50` and loops until queue empty.
- Server `PosSyncPushService::MAX_OPERATIONS = 50` remains as safety bound.
- Documented: 1000 offline ops = **20 sequential HTTP requests**, not 1 giant payload.

### Residual
- No server-side async batch job for mega-backlogs; acceptable for current offline UUID semantics.
- HTTP timeout/retry tuning under very poor networks not benchmarked.

---

## 5. BOOTSTRAP (NF-02 / H-12)

### Finance bootstrap (`BootstrapController`)
- Caps: customers **200**, products **500**, suppliers/categories **200**, tax **100**.
- Added `catalog_meta.*.has_more` via limit+1 fetch pattern.
- Selected columns only (no full model hydration).

### Cashier catalog
- Categories capped at 200 (existing).

### Tests
- `BootstrapBoundedTest` asserts caps and `has_more` meta.

### Residual
- Bootstrap is **bounded**, not cursor-paginated API; clients needing full catalogs must use list endpoints.

---

## 6. REPORTING

### Cashier daily report (`ReportController` + `PosOrderStatsService`)
**Before:** Loaded all orders for date range with items; summary computed in PHP from collections.

**After:**
- `dailyReportSummary()` — **2 aggregate SQL queries** (orders + invoices).
- Optional compact mode: `include_orders=0&include_invoices=0` skips order/invoice arrays.
- Default `orders_limit=500`, invoices capped at 500.
- Line-level stats (`top_items`, `quantity_by_type`) use DB `GROUP BY` on closed orders.
- Removed `items` eager load from order list.

**Evidence (SQLite, synthetic 5 orders):**
- Summary aggregate: **≤3 queries** (`CashierDailyReportPerformanceTest`).

### Finance dashboards
- Not fully re-profiled this pass; existing finance services use scoped queries. **Scale risk documented** — see §18.

---

## 7. N+1

| Path | Fix |
| --- | --- |
| M-06 CRM timeline | Per-source cap `ceil(limit/4)`, selective columns, max limit 100 |
| M-07 Inbox unread | Replaced correlated `MAX(id)` subquery with grouped join subquery |
| M-10 Table list | Orders limited to 15; restricted item columns |
| M-16 Campaign | Existing `chunkById(20)` dispatch; per-recipient job isolation retained |

**Campaign note:** `sendRecipient()` still loads campaign+account per job (intentional isolation). Bulk preload would need queue contract change.

---

## 8. INDEXES

| Query | Index | Migration |
| --- | --- | --- |
| POS orders by workspace + day | `(workspace_id, placed_at)` | `2026_09_18_120000_add_performance_indexes_for_orders_and_invoices.php` |
| Finance invoice refresh window | `(workspace_id, updated_at)` | same |
| Inbox inbound max message id | `(conversation_id, direction, id)` | `2026_09_18_121000_add_messages_inbound_lookup_index.php` |

**Tradeoff:** Additional write overhead on hot insert paths (orders, messages, invoices). Chosen only where query evidence exists.

---

## 9. CACHE

### M-08 Entitlements
- `FeatureAccessService::enabledFeatureSet()` — `Cache::remember` **2 minutes**, key `workspace:{id}:enabled_features:v1`.
- Invalidation wired on:
  - `WorkspaceFeatureFlag` saved/deleted
  - Subscription create/cancel (`SubscriptionService`)

### Not cached (by design)
- Financial totals, invoice balances, ledger entries — accounting truth must not be TTL-stale.

### Residual
- `WorkspaceAddon` changes should also call `forgetWorkspaceFeatureCache` (low frequency, 2m TTL bounds staleness).

---

## 10. SCHEDULERS (NF-14)

### Before
- Hourly issued-invoice refresh scanned all finance invoices platform-wide via single `chunkById`.

### After (`InvoiceStateService::refreshIssuedStatuses`)
- Platform path: `Workspace::chunkById(100)` → per-workspace body.
- Refresh window filter: `updated_at >= now()-26h`, due-date overdue, unpaid/partial/overdue statuses.
- Per-workspace invoice `chunkById(200)` unchanged semantics.

**Effect:** Workspaces with no qualifying invoices skip heavy invoice scans; changed invoices prioritized by window.

---

## 11. AI PERFORMANCE

Finance/Cashier assistant tools already enforce:
- Bounded tool registry with period limits and top-N caps (existing architecture).
- Chat catalog search: SQL `limit(40)`, PHP score filter `>= 2`, final `limit ≤ 20`.

**Not enabled:** `FINANCE_ASSISTANT_AGENT` production path (per plan rules).

**Residual:** No latency benchmarks for multi-tool finance questions at 100K orders (synthetic benchmark not run).

---

## 12. PAYLOAD SIZE (M-09)

| Endpoint | Before | After |
| --- | --- | --- |
| Cashier daily report | All orders + items | Summary-only mode; lists capped/truncatable |
| Finance bootstrap | Large arrays, no meta | Same caps + `has_more` flags |
| Table list | Deep nested items | 15 orders, trimmed columns |

Compact daily report meta includes `include_orders`, `include_invoices`, truncation flags.

---

## 13. MEMORY

Cashier daily report no longer hydrates full order collections for summary path. CRM timeline bounded. Campaign processing uses `chunkById(20)` — does not load all recipients into one job.

---

## 14. SCALE TESTS

**Synthetic benchmark (SQLite in-memory — not production scale):**

| Dataset | Query | Result |
| --- | --- | --- |
| 5 POS orders | `dailyReportSummary` | 5 count, ≤3 queries |
| 30 catalog items | `searchForPrompt(..., limit=12)` | ≤12 results |
| Finance bootstrap empty workspace | GET bootstrap | customers ≤200, products ≤500 |

**Not run:** 100K orders / 1M order items / 100K invoices on MySQL. Claims below are **design + unit evidence**, not production scale verification.

---

## 15. BEFORE / AFTER

| Path | Before | After | Evidence |
| --- | --- | --- | --- |
| H-13 Cashier polling | 5s fixed always | 5s active → 30s idle backoff | Flutter code + config |
| H-14 Cashier sync | 50 cap (looked like single batch) | Client loops 50-op chunks | `sync_engine_v2.dart` |
| H-12/NF-02 Bootstrap | Caps only | Caps + `has_more` meta | `BootstrapBoundedTest` |
| M-09 Daily report | Full order load | DB aggregates + optional compact | `CashierDailyReportPerformanceTest` |
| M-07 Inbox unread | Correlated subquery | Join aggregated inbound max | Code + index migration |
| M-08 Entitlements | Recompute every call | 2m cache + invalidation hooks | `FeatureAccessService` |
| M-06 CRM timeline | Unbounded multi-query | Capped per-source queries | `CustomerCrmService` |
| M-10 Table list | Deep eager load | Limited orders/columns | `TableController` |
| M-17 Chat search | Unbounded OR LIKE | limit 40 + score floor + top 20 | `ChatCatalogSearchBoundedTest` |
| NF-14 Invoice refresh | Platform invoice scan | Workspace-partitioned + window | `InvoiceStateService` |
| Indexes | Missing composite on hot filters | 3 targeted indexes | Migrations |

---

## 16. TEST RESULTS

```
Performance:
  Passed: 4
  Failed: 0
  Errors: 0
  Skipped: 0

Critical (SQLite):
  Passed: 425
  Failed: 0
  Errors: 0
  Skipped: 2

MySQL:
  Passed: (not re-run this pass)
  Failed: —
  Errors: —
  Skipped: —
```

New tests added to `phpunit.critical.xml`:
- `tests/Feature/Feature/Performance/BootstrapBoundedTest.php`
- `tests/Feature/Feature/Performance/CashierDailyReportPerformanceTest.php`
- `tests/Unit/Performance/ChatCatalogSearchBoundedTest.php`

---

## 17. FINDINGS UPDATED

| ID | Status | Notes |
| --- | --- | --- |
| H-13 | **PARTIALLY_FIXED** | Adaptive idle polling shipped; active-screen 5s retained |
| H-14 | **PARTIALLY_FIXED** | Client chunk loop verified; server async redesign not needed for bounded sync |
| H-12 | **PARTIALLY_FIXED** | Bootstrap bounded + `has_more`; paginated bootstrap API deferred |
| M-06 | **PARTIALLY_FIXED** | Timeline capped; not unified single-query timeline |
| M-07 | **FIXED** | Join subquery + index |
| M-08 | **PARTIALLY_FIXED** | Cache + invalidation on flags/subscriptions; addon hook pending |
| M-09 | **PARTIALLY_FIXED** | Compact mode + caps; default still includes orders unless opted out |
| M-10 | **FIXED** | Table list eager load trimmed |
| M-16 | **OPEN** | chunkById exists; N+1 per recipient job acceptable for isolation |
| M-17 | **PARTIALLY_FIXED** | Bounded + scored; still LIKE-based (no FTS) |
| NF-02 | **PARTIALLY_FIXED** | Same as H-12 |
| NF-14 | **PARTIALLY_FIXED** | Workspace partition + window; not queued shard jobs |

---

## 18. REMAINING RISKS

1. **No MySQL 100K/1M synthetic benchmark** — SQLite tests do not predict InnoDB scan behavior.
2. **Finance dashboard / P&L / aging** — not SQL-profiled at large tenant size this pass.
3. **Chat catalog LIKE `%term%`** — cannot use B-tree index for contains search; bounded but still table scan within workspace.
4. **Cashier daily report** with `include_orders=1` still loads up to 500 orders (by design).
5. **Entitlements** — 2m TTL + partial invalidation; addon grant changes may stale briefly.
6. **H-13 active polling** — still 5s on busy screens until idle backoff.
7. **AI multi-tool latency** — not measured under large datasets.

---

## 19. PLAN 5 VERDICT

## **PASS WITH DOCUMENTED SCALE RISKS**

**Rationale:**
- Critical acceptance items addressed with code + tests: bootstrap bounds, daily report aggregates, inbox query, entitlements cache, scheduler partitioning, indexes, cashier polling/sync architecture documented.
- H-13/H-14 meet "improved or product decision recorded."
- Full **production-scale** verification and finance reporting profiling remain open and are explicitly documented — not claimed as verified.

---

*End of Plan 5 report.*
