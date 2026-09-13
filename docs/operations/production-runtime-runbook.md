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
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=5 --timeout=200
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

One documented mechanism: **Supervisor**. Template: `deploy/supervisor/hasem-worker.conf`.

```bash
sudo cp deploy/supervisor/hasem-worker.conf /etc/supervisor/conf.d/hasem-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status hasem-worker:*
```

Logs: `storage/logs/worker.log` and `storage/logs/laravel.log`. Failures: `php artisan queue:failed`.

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
