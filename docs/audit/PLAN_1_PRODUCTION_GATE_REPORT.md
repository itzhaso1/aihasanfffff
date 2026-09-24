# PLAN 1 — Production Gate Report

Date: 2026-09-18  
Scope: MySQL isolation/concurrency, Redis/queue/cache/session verification, runtime checks, production-critical idempotency/concurrency only.

---

## 1. BASELINE

| Suite | Command | Passed | Failed | Errors | Skipped |
|---|---|---:|---:|---:|---:|
| SQLite Critical (before fixes) | `php artisan test --configuration=phpunit.critical.xml` | 397 | 0 | 0 | 2 |
| MySQL Critical (before fixes, fresh schema) | `phpunit -c phpunit.critical.mysql.xml` | 373 | 21 | 2 | 0 |
| Full PHPUnit (before Plan 1 fixes) | `phpunit` | 1020 | 21 | 138 | 13 |
| Finance Agent (SQLite) | `--filter FinanceAgent` | 11 | 0 | 0 | 2 |

Final re-run after Plan 1 fixes:

| Suite | Passed | Failed | Errors | Skipped |
|---|---:|---:|---:|---:|
| SQLite Critical | 397 | 0 | 0 | 2 |
| MySQL Critical | **400** | **0** | **0** | 0 |
| Finance Agent | 11 | 0 | 0 | 2 |
| Full PHPUnit | 1021 | 20 | 138 | 17 |

---

## 2. MYSQL

### DB version
- **Engine:** MariaDB 10.4.32 (via `tests/bin/mysql-p0-probe.php`)
- **Test database:** `hasem_p0_tenancy_test` (isolated; production name `hasem` blocked by `tests/bin/assert-not-production-database.php`)

### Fresh schema
- Added `tests/bootstrap-mysql-critical.php`: runs `migrate:fresh --database=mysql --force` before every MySQL critical run, then resets `RefreshDatabaseState::$migrated`.
- Manual verification: `php artisan migrate:fresh --database=mysql --force` succeeds on clean test DB (all migrations through `2026_09_17_220000_add_subscription_billing_attempt_period_guard`).

### Test result (after fixes)
```
MySQL Critical: 400 passed, 0 failed, 0 errors, 0 skipped
```

### Every failure root cause (original 21 failures + 2 errors)

| # | Test | Failure | Root cause | Type | Fix |
|---|---|---|---|---|---|
| 1 | `FinanceAgentMysqlConcurrencyTest::test_concurrent_requests_on_the_same_conversation_are_serialized` | Expected one 200 + one 409 (or single 200); got two 200 | **Test flake:** second worker started after first finished when suite was warm (no guaranteed overlap). Application lock (`processing_started_at` + `lockForUpdate`) is correct when requests overlap. | Test defect | Added `gemini_delay_ms` to worker payloads; moved class to end of suite. |
| 2 | `FoundationSeederPlatformAdminPasswordTest::test_platform_admin_factory_remains_independent_of_seed_password_rules` | Count 1 vs 0 for `admin@hasem.local` | **Test pollution:** `FinanceAgentMysqlConcurrencyTest` seeded `FoundationSeeder` with `connectionsToTransact() => []`, committing platform admin before this test. | Test isolation | Removed seeder from concurrency class; moved class to end of suite. |
| 3–10 | `FinancialCoreTest` (8 tests) | Wrong totals (`5000.00`), wrong status, 404 on routes | **Test pollution:** committed `5000.00` invoice from concurrency test; `FinanceInvoice::withoutGlobalScopes()->firstOrFail()` picked polluted row. | Test isolation | Suite ordering + bootstrap fresh schema; no production code change required for invoice math. |
| 11 | `ExpenseAccountingIntegrityTest::test_draft_expense_delete_removes_row_without_journal` | Row count 2 vs 0 | Polluted finance rows from committed concurrency test data. | Test isolation | Same as above. |
| 12–16 | `BillingLifecycleTest` (4 failures + 1 error) | Wrong amounts/status, 404, missing session error, cross-workspace write | Polluted invoices + workspace context confusion from leftover rows. | Test isolation | Same as above. |
| 17–19 | `PlatformCoreTest` (2 failures + 1 error) | Null journal, wrong counts | Polluted finance/product rows. | Test isolation | Same as above. |
| 20 | `AiSecurityPrivacyCostTest::test_finance_copilot_cannot_switch_workspace_or_create_invoices` | Count 2 vs 0 | Polluted invoice rows. | Test isolation | Same as above. |

**Summary:** 20/21 failures and 2/2 errors were **test isolation / ordering** caused by `FinanceAgentMysqlConcurrencyTest` committing data mid-suite. One failure was a **concurrency test design flake** (non-overlapping requests), not an application safety bug.

### Code fixes shipped (production-relevant)
1. **`WorkspaceOrderNumberGenerator`:** `insertOrIgnore` for sequence row initialization (safe concurrent first allocation).
2. **`OrderService::create`:** Lock products before allocating order numbers (consistent lock order); retry up to 5 times on MySQL deadlock/lock timeout (1213/1205).
3. **Test infrastructure:** `tests/bootstrap-mysql-critical.php`, suite reorder, worker overlap delay, `MysqlProductionGateConcurrencyTest`.

---

## 3. MYSQL CONCURRENCY

### Conversation
| Scenario | Result | Evidence |
|---|---|---|
| Same conversation, two simultaneous asks | **PASS** | One 200 + one 409 when overlap guaranteed (`gemini_delay_ms=400`); ≤1 user message; metering = 1; `processing_started_at` cleared. |
| Same idempotency key, two simultaneous asks | **PASS** | One execution; shared conversation id; ≤4 Gemini calls total. |
| Different idempotency keys, same conversation | Covered by serialization test (only one turn when overlapping). | |
| Different workspaces simultaneously | **PASS** (finance agent tenant isolation tests in critical suite). | |

**Production invariant:** `FinanceAiConversationService::beginTurn` uses `lockForUpdate` + `processing_started_at` timeout → `FinanceAiConversationBusyException` (409). Safe when requests overlap.

### Orders
| Scenario | Result |
|---|---|
| 100 concurrent creates, same workspace | **PASS** — 100 orders, 100 unique numbers |
| 25+25 cross-workspace parallel | **PASS** — no cross-workspace collision |

**Production fix:** product locks before sequence allocation prevents deadlocks under high concurrency on shared catalog lines.

### Subscriptions
| Scenario | Result |
|---|---|
| Concurrent renewal workers (`processRenewals` ×2) | **PASS** — 1 renewal billing attempt |
| Sequential double renewal (existing critical test) | **PASS** — `ProductionHardeningRemediationTest` |

### Payments
| Scenario | Result |
|---|---|
| Two simultaneous `createPaymentLink` workers | **PASS** — same `payment_id`, 1 row |
| Sequential reuse (existing critical test) | **PASS** |

**Assessment:** Residual race before second transaction observes pending row is mitigated by row reuse under lock in `PaymentService::createPaymentLink`. Concurrent worker test confirms single pending payment per order on MySQL.

### WhatsApp
| Scenario | Result |
|---|---|
| Duplicate inbound webhook payload | **PASS** — `ProductionHardeningRemediationTest` |
| Job marks processed after persist | **PASS** |
| AI reply idempotency per inbound message | **PASS** |

---

## 4. REDIS

### Connectivity
- **Status:** `NEEDS RUNTIME VERIFICATION`
- Local probe (`tests/bin/redis-probe.php`): `phpredis extension not loaded`
- `runtime:check --production-strict`: Redis checks **FAIL** on this dev machine (database queue/cache/session configured)

### Uniqueness
- **Design verified in code/tests:** `ProcessIncomingWhatsAppMessage` and `ProcessAIResponse` implement `ShouldBeUnique`.
- **Redis-backed uniqueness:** Not exercised locally (no Redis client). Must be verified in production/staging with `QUEUE_CONNECTION=redis`.

### Queues / cache / locks
- PHPUnit critical runs intentionally use `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`.
- `ProductionRuntimeTest` validates production-like config expectations and database queue retry/failure paths in testing.

### Tenant safety (cache key sample)
- `UpdateAIUsage` uses `workspace:{id}:ai_usage_tokens` — **scoped correctly**.
- OTP keys are phone-scoped (global by design).
- Runtime probe jobs use unique per-run keys.

No critical unscoped workspace dashboard keys found in `Cache::remember` / `Cache::put` grep sample; full audit deferred to later plans.

---

## 5. AFTER COMMIT

- `config/queue.php`: all connections have `after_commit => false` (including redis).
- **Classification:** Jobs dispatched inside active DB transactions (order create events, finance flows) currently may enqueue before commit.
- **Plan 1 action:** Did **not** globally flip `after_commit` (would risk SQLite test behavior). Production should set `'after_commit' => true` on the **redis** connection only in deployment env when moving off sync/database queue.
- `ProductionRuntimeTest` covers sync probe jobs and database queue retry; rollback+dispatch regression remains a documented deployment configuration task.

---

## 6. RUNTIME CHECK

Command: `php artisan runtime:check --production-strict`

| Check | Dev machine result |
|---|---|
| queue_timeout_budget | PASS |
| scheduler / failed_jobs | PASS |
| production_queue/cache/session | **FAIL** (database, expected in local `.env`) |
| redis | **FAIL** (ext-redis not loaded) |

**Interpretation:** Application runtime checks work; this workstation is not a production-like Redis deployment. Staging/production must run the same command with Redis configured to complete the gate.

---

## 7. MIGRATIONS

- `migrate:fresh` on `hasem_p0_tenancy_test`: **PASS** (all migrations apply cleanly on empty MySQL).
- Critical production migrations present and applied:
  - WhatsApp composite uniques (`2026_09_17_100000_scope_whatsapp_meta_ids_per_workspace`)
  - Subscription guards (`2026_09_17_100100`, `2026_09_17_220000`)
  - Order sequences (`2026_09_17_100200`)
  - Finance AI conversations + idempotency (`2026_09_17_194800`, `2026_09_17_211500`)
  - P1C idempotency workspace binding (`2026_09_13_170000`)

---

## 8. TEST RESULTS

```
SQLite Critical:
Passed: 397
Failed: 0
Errors: 0
Skipped: 2

MySQL Critical:
Passed: 400
Failed: 0
Errors: 0
Skipped: 0

Finance Agent:
Passed: 11
Failed: 0
Errors: 0
Skipped: 2

Full PHPUnit:
Passed: 1021
Failed: 20
Errors: 138
Skipped: 17
```

**Note:** Full suite failures/errors are predominantly Cashier/POS workspace-context test setup issues (`MissingWorkspaceContextException`) — **out of Plan 1 scope** per strict scope rules.

---

## 9. REMAINING RISKS

1. **Redis not verified on this machine** — unique jobs, queue workers, cache, session, and production-strict runtime require staging/production Redis with `ext-redis` or `predis`.
2. **`after_commit` on redis queue** — must be enabled in deployment config for jobs that depend on committed rows (not globally changed in Plan 1).
3. **Full PHPUnit suite** — 138 errors remain in non-critical Cashier/POS paths; not blocking Plan 1 gate but block general CI green.
4. **Conversation concurrency under load** — logic proven with overlapping MySQL workers; extreme load/failover scenarios belong to later performance plans.
5. **Payment provider double-hit** — DB row reuse confirmed under concurrent workers; external provider idempotency keys remain best practice for live gateways.

---

## 10. PRODUCTION GATE

```
PASS WITH RUNTIME VERIFICATION REQUIRED
```

### Rationale
- **MySQL critical:** 0 failures, 0 errors on fresh isolated schema (**PASS**).
- **MySQL concurrency:** conversation, orders (100), subscriptions, payments, WhatsApp proven on MySQL (**PASS**).
- **Redis / production-strict runtime:** not verified on actual Redis in this environment (**RUNTIME VERIFICATION REQUIRED**).
- **`FINANCE_ASSISTANT_AGENT`:** not enabled.
- **Flutter:** not modified.

### Files changed (Plan 1)
- `tests/bootstrap-mysql-critical.php` (new)
- `phpunit.critical.mysql.xml` (bootstrap + suite order)
- `tests/Feature/Feature/Finance/FinanceAgentMysqlConcurrencyTest.php`
- `tests/Feature/Feature/ProductionGate/MysqlProductionGateConcurrencyTest.php` (new)
- `tests/bin/finance-assistant-ask-worker.php`
- `tests/bin/order-create-worker.php` (new)
- `tests/bin/payment-link-worker.php` (new)
- `tests/bin/subscription-renewal-worker.php` (new)
- `tests/bin/redis-probe.php` (new)
- `app/Services/Order/WorkspaceOrderNumberGenerator.php`
- `app/Services/Order/OrderService.php`

### Recommended next steps (outside Plan 1)
1. Run `php artisan runtime:check --production-strict` on staging with Redis queue/cache/session.
2. Dispatch duplicate `ProcessIncomingWhatsAppMessage` / `ProcessAIResponse` on Redis and confirm single execution.
3. Set `after_commit => true` on redis queue connection in production env after reviewing transactional job dispatch points.
