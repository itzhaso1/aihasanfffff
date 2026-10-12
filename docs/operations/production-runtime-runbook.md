# Production runtime (Redis, workers, scheduler)

This is the first-party runbook for queue/cache/session/scheduler. It does **not** claim the live production host is ready until `runtime:check --production-strict` and a real Redis worker probe have passed on that host.

Local/testing stay on `database` / `array` / `sync` so SQLite PHPUnit keeps working.

## Production target

| Surface | Value |
| --- | --- |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_STORE` | `redis` |
| `SESSION_DRIVER` | `redis` |
| `SESSION_CONNECTION` | `session` (Redis DB `2`) |
| Failed jobs | `database-uuids` / `failed_jobs` table (kept) |
| Scheduler | `* * * * * php /var/www/hasem/artisan schedule:run` |
| Worker | Supervisor + `queue:work redis` (not `queue:listen`) |
| `REDIS_QUEUE_AFTER_COMMIT` | `true` |

Behind Nginx (or another reverse proxy), set `TRUSTED_PROXIES` in the host `.env` so `X-Forwarded-*` is trusted only from the edge — see `.env.example`. Production HTTPS also needs `SESSION_SECURE_COOKIE=true` once forwarded proto is trusted.

Production Redis queue must set `REDIS_QUEUE_AFTER_COMMIT=true` so jobs dispatched during a DB transaction are pushed only after commit. After changing queue config, run `php artisan queue:restart` (or restart Supervisor workers) so workers reload settings.

Never set `QUEUE_CONNECTION=failover` or `CACHE_STORE=failover` in production. Those stores silently fall back to the database.

## Redis

Placeholders only (set real values in the host `.env`, never commit them):

```
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_USERNAME=
REDIS_PASSWORD=
REDIS_SCHEME=tcp
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2
REDIS_PREFIX=hasem-database-
```

TLS: `REDIS_SCHEME=tls`. Isolated verification must use a local/dev Redis, never a production Redis database.

## Queue budget

Longest first-party job timeout today: `SyncEmailInboxJob` = **180s**.

| Setting | Value | Why |
| --- | --- | --- |
| `--timeout` / `QUEUE_WORKER_TIMEOUT` | 200 | covers the longest job |
| `REDIS_QUEUE_RETRY_AFTER` | 270 | greater than worker timeout |
| `--tries` | 3 | worker default; jobs may set their own |
| `--backoff` | 5 | worker default |

Rule: `max_job_timeout <= worker_timeout < retry_after`.

```bash
php artisan queue:work redis --queue=emails,default --sleep=1 --tries=3 --backoff=5 --timeout=200
# or
php artisan runtime:work
```

## Failed jobs

```bash
php artisan queue:failed
php artisan queue:retry {id}
php artisan queue:forget {id}
php artisan queue:flush
```

Use `App\Jobs\Runtime\RuntimeFailingJob` for drills. Do not retry-test payment, subscription, or POS jobs as a runtime probe.

## Scheduler inventory

| Command / job | Frequency | Overlap | Multi-node |
| --- | --- | --- | --- |
| `finance:invoices:refresh-payment-status` | hourly | `withoutOverlapping(50)` | `onOneServer` |
| `finance:invoices:send-reminders` | daily 08:00 | `withoutOverlapping(120)` | `onOneServer` |
| `finance:billing-schedules:generate` | daily 01:15 | `withoutOverlapping(120)` | `onOneServer` |
| `ExpireStoriesJob` | hourly | `withoutOverlapping(50)` | `onOneServer` |
| `subscriptions:expire-trials` | hourly | `withoutOverlapping(50)` | `onOneServer` |
| `subscriptions:process-renewals` | hourly | `withoutOverlapping(50)` | `onOneServer` |
| `subscriptions:process-dunning` | hourly | `withoutOverlapping(50)` | `onOneServer` |
| `backup:database` | every 6 hours | `withoutOverlapping(120)` | `onOneServer` |

`onOneServer` uses the cache store. In production that is Redis.

Host cron (required; schedule registration alone is not proof the clock is running):

```
* * * * * php /var/www/hasem/artisan schedule:run >> /var/www/hasem/storage/logs/scheduler.log 2>&1
```

## Supervisor

One documented mechanism: **Supervisor**. P0 templates (all use `queue:work redis`, no secrets in files):

| File | Program | Queue | Processes |
| --- | --- | --- | --- |
| `deploy/supervisor/hasem-worker-emails.conf` | `hasem-worker-emails` | `emails` | 1 |
| `deploy/supervisor/hasem-worker.conf` | `hasem-worker` | `default` | 2 |
| `deploy/supervisor/hasem-worker-inbound.conf` | `hasem-worker-inbound` | `default` | 2 |
| `deploy/supervisor/hasem-worker-ai.conf` | `hasem-worker-ai` | `default` | 1 |

P1 inbox jobs use dedicated Redis queues: **`inbound`**, **`realtime`**, **`ai`** (see `docs/operations/production-realtime-runbook.md`).

P2 channel outbound (WhatsApp `SendWhatsAppMessage`, Instagram/Facebook delivery jobs) uses the **`outbound`** queue (`deploy/supervisor/hasem-worker-outbound.conf`, `INBOX_QUEUE_OUTBOUND`). Residual work stays on **`default`** / **`emails`**.

P4 inbox AI replies run on **`ai`** (`hasem-worker-ai`). Inbox agent memory extraction runs on **`default`** (`AI_INBOX_MEMORY_QUEUE`) so it does not compete with inbound/realtime/outbound/media workers.

```bash
sudo cp deploy/supervisor/hasem-worker*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Logs: `storage/logs/worker*.log` and `storage/logs/laravel.log`. Failures: `php artisan queue:failed`.

Production `.env` must include `QUEUE_CONNECTION=redis` (not `database`). After changing queue settings: `php artisan queue:restart`.

## Deploy / graceful restart

1. Deploy code and run migrations.
2. `php artisan queue:restart` — workers finish the current job, then exit.
3. Supervisor starts new workers with the new code.
4. Confirm `sudo supervisorctl status` and `php artisan runtime:check --production-strict`.

Do not kill `-9` workers mid-job.

## Health

```bash
php artisan runtime:check
php artisan runtime:check --production-strict
php artisan runtime:check --production-strict --require-redis
php artisan config:show queue
php artisan config:show cache
php artisan config:show session
php artisan config:show database
```

`runtime:check` is read-only (config, schema, Redis ping). It does not enqueue business jobs.

## Job retry review (not redesigned)

| Job | Retry notes |
| --- | --- |
| `ProcessPaymentWebhook` / `ProcessSubscriptionWebhook` | Provider event idempotency keys already exist. REVIEW if a new provider is added. |
| `CreatePaymentLink` | Relies on payment service; retry can create a second attempt if the first persisted a link. REVIEW. |
| `UpdateAIUsage` | Retry with `tokens > 0` can consume usage again. REVIEW. |
| POS sync | P0-2 idempotency unchanged. |
| Notifications / WhatsApp / email | Outbound rows track attempts; not exactly-once. REVIEW. |
