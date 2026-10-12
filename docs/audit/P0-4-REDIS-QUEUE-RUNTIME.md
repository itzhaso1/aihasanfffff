# P0-4 FINAL

**Date:** 2026-09-13  
**P0-1 / P0-2 / P0-3:** CLOSED — not reopened.  
**Scope:** production runtime / Redis / queue / cache / session / scheduler / worker supervision.

## Status

**CLOSED**

Real isolated Redis verification passed on this development host. Production `.env` was not switched. No production Redis was used. P0-1 / P0-2 / P0-3 were not reopened. P0-5 was not started.

This is **not** a claim that the live production host is running Redis/Supervisor.

---

## Findings Before Fix

1. `.env.example` and config defaults were `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`. Production Redis was only a comment.
2. Redis connection keys existed (`host`/`port`/`password`/`db` 0 and cache db 1). No TLS scheme, no session Redis DB, no username placeholder in `.env.example`.
3. `retry_after` default was **90s**. Longest job timeout is `SyncEmailInboxJob` **180s**. A still-running worker could be treated as dead and the job released early.
4. Runbook worker command had no `--timeout` and used `queue:work` without `redis`.
5. `failed_jobs` migration and `database-uuids` driver already existed. No first-party failing-job drill.
6. No Horizon. No docker-compose. No Supervisor/systemd unit in-repo. No first-party CI.
7. Scheduler had `withoutOverlapping` on all 8 events. **No `onOneServer`** — duplicate billing/reminders/backup possible on more than one process/node.
8. Laravel `failover` queue/cache stores can silently fall back to database. They were not the default, but nothing blocked using them in production.
9. `phpredis` was the configured client; the extension is not installed here. `predis` was not a dependency.
10. Session `database` is Laravel’s local default, not a documented product requirement. Production can use Redis sessions.
11. Sensitive jobs (payment/subscription webhooks) already use idempotency keys. `UpdateAIUsage` retry can double-consume tokens — REVIEW only.

---

## Production Runtime Target

| Surface | Target |
| --- | --- |
| queue | `redis` (`REDIS_QUEUE=default`, `retry_after=270`) |
| cache | `redis` (Redis DB `1`) |
| session | `redis` (`SESSION_CONNECTION=session`, Redis DB `2`) |
| scheduler | cron `* * * * * php artisan schedule:run` |
| worker | Supervisor + `queue:work redis --timeout=200` (not `queue:listen`, not Horizon) |

Local/testing remain `database` / `array` / `sync`.

---

## Redis Verification

| Field | Value |
| --- | --- |
| implementation | tporadowski Redis for Windows (disposable, `%TEMP%\hasem-redis-p04`) |
| version | **5.0.14.1** |
| host | `127.0.0.1` |
| port | `6380` |
| DB / namespace | DB `14`, prefix `hasem-p04-` |
| client | **predis** (`ext-redis` not installed; not required when `REDIS_CLIENT=predis`) |
| start | `redis-server.exe redis-p04.conf` (bind 127.0.0.1, no persistence) |
| stop | `redis-cli.exe -h 127.0.0.1 -p 6380 SHUTDOWN NOSAVE` |
| result | **PASS** |

`php artisan runtime:check --production-strict --require-redis` = **PASS**  
(`queue`/`cache`/`session` = redis; ping to `127.0.0.1:6380`; password omitted from output).

Instance was shut down after the drill. Binaries were not committed.

---

## Queue Verification

| Step | Result |
| --- | --- |
| job | `RuntimeProbeJob` / `RuntimeFailingJob` (test-only) |
| dispatch | PASS on Redis (`cache_before=NULL`) |
| processed | PASS — `queue:work redis --queue=default --sleep=1 --tries=3 --backoff=5 --timeout=200 --once` → `DONE`, cache=`processed` |
| failed job | PASS (`failed_jobs` via Redis worker) |
| retry | PASS (`queue:retry` then Redis worker → `recovered`) |
| database fallback | not used (`queue.default=redis`) |

Timeout budget: `max_job=180 <= worker=200 < retry_after=270`.

---

## Scheduler

| Command / job | Frequency | Overlap |
| --- | --- | --- |
| `finance:invoices:refresh-payment-status` | hourly | `withoutOverlapping(50)` + `onOneServer` |
| `finance:invoices:send-reminders` | daily 08:00 | `withoutOverlapping(120)` + `onOneServer` |
| `finance:billing-schedules:generate` | daily 01:15 | `withoutOverlapping(120)` + `onOneServer` |
| `ExpireStoriesJob` | hourly | `withoutOverlapping(50)` + `onOneServer` |
| `subscriptions:expire-trials` | hourly | `withoutOverlapping(50)` + `onOneServer` |
| `subscriptions:process-renewals` | hourly | `withoutOverlapping(50)` + `onOneServer` |
| `subscriptions:process-dunning` | hourly | `withoutOverlapping(50)` + `onOneServer` |
| `backup:database` | every 6 hours | `withoutOverlapping(120)` unchanged + `onOneServer` |

`runtime:check` on this host: 8 events registered. Host cron is still operator-owned.

---

## Tests

`php artisan test --testdox tests/Feature/Feature/Runtime`

**Runtime:** PASS 15 · FAIL 0 · SKIPPED 0 · ERRORS 0  

`RedisRuntimeTest`: PASS 6 · FAIL 0 · SKIPPED 0 · ERRORS 0  

**Relevant regression** (auth, backup, payment webhook tenancy, POS tax/idempotency, runtime): PASS 46 · FAIL 0 · SKIPPED 0 · ERRORS 0

---

## Files Changed

### New

- `config/runtime.php`
- `app/Services/Runtime/RuntimeHealthService.php`
- `app/Console/Commands/RuntimeCheckCommand.php`
- `app/Console/Commands/RuntimeWorkCommand.php`
- `app/Jobs/Runtime/RuntimeProbeJob.php`
- `app/Jobs/Runtime/RuntimeFailingJob.php`
- `tests/Feature/Feature/Runtime/ProductionRuntimeTest.php`
- `tests/Feature/Feature/Runtime/RedisRuntimeTest.php`
- `deploy/supervisor/hasem-worker.conf`
- `docs/operations/production-runtime-runbook.md`
- `docs/audit/P0-4-REDIS-QUEUE-RUNTIME.md`

### Updated

- `config/queue.php` — Redis/database `retry_after` 270; failover warning
- `config/cache.php` — failover warning
- `config/database.php` — `REDIS_SCHEME`, `session` Redis DB
- `.env.example` — production Redis placeholders (no secrets)
- `routes/console.php` — `onOneServer()` on scheduled events
- `composer.json` / `composer.lock` — `predis/predis`
- `docs/PRODUCTION.md`
- `docs/operations/production-runbook.md`
- `docs/deployment.md`

---

## Runbook / Deployment

`docs/operations/production-runtime-runbook.md`

```bash
php artisan runtime:check --production-strict
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=5 --timeout=200
php artisan queue:restart
```

Supervisor: `deploy/supervisor/hasem-worker.conf`  
Cron: `* * * * * php /var/www/hasem/artisan schedule:run`

This Windows development host does not run Supervisor. The runbook was not executed against a live Linux worker farm.

---

## Remaining Review

- Local `.env` still uses database queue/cache/session. Production host must set Redis drivers; this drill used process env only.
- Host cron + Supervisor install are still operator-owned.
- Disposable Windows Redis 5.0.14.1 is not a production Linux Redis install.
- `UpdateAIUsage` retry may double-count tokens (REVIEW).
- `CreatePaymentLink` retry may create a second link (REVIEW).
- Notification/WhatsApp/email jobs are not exactly-once (REVIEW).
- POS idempotency remains P0-2; not changed.

Do not start P0-5 from this document.
