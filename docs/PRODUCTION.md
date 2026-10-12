# Production checklist

Use this before going live. Local defaults in `.env.example` stay developer-friendly (`APP_DEBUG=true`, database queue/cache/session).

## Application

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` set and unique per environment
- [ ] `APP_URL` is the public HTTPS origin
- [ ] `LOG_LEVEL=error` or `warning` (not `debug`)
- [ ] Never commit real API keys, webhook secrets, or SMTP passwords

## Sessions and cookies

- [ ] `SESSION_ENCRYPT=true`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_SAME_SITE=lax` (or `strict` if no cross-site flows)

## Queue / cache / session drivers

Database drivers are the safe local default. Production target (see `docs/operations/production-runtime-runbook.md`):

- [ ] `QUEUE_CONNECTION=redis`
- [ ] `CACHE_STORE=redis`
- [ ] `SESSION_DRIVER=redis` (`SESSION_CONNECTION=session`)
- [ ] Supervisor worker from `deploy/supervisor/hasem-worker.conf`
- [ ] `php artisan runtime:check --production-strict`
- [ ] Failed jobs inspected (`php artisan queue:failed`)

Do **not** switch local/test defaults to Redis without an available Redis instance. Do not use `failover` drivers in production.

## Auth tokens

Sanctum `expiration` stays `null` so per-token `expires_at` wins:

- Core API: `config('security.api_token_days')` (default 30)
- Mobile / cashier: `config('security.mobile_token_days')` (default 60)

## CORS

`config/cors.php` does **not** allow `*`. Set `CORS_ALLOWED_ORIGINS` only if a browser SPA on another origin calls the API with cookies/credentials.

## Payments

- [ ] `PAYMENT_DEFAULT_PROVIDER` is the live provider when accepting real money
- [ ] HyperPay / Stripe secrets set; webhook secrets required
- [ ] `HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=false`

## Scheduler

Scheduled commands use `withoutOverlapping()`. Confirm cron:

```
* * * * * php /path/to/artisan schedule:run
```

`backup:database` is registered every six hours. Cron `schedule:run` is required; the schedule hook alone is not proof that backups exist.

## Backups

First-party runbook: `docs/operations/backup-restore-runbook.md`.

- Database dumps go to the private `backups` disk (`storage/app/private/backups`), never `public/` or `storage/app/public/`.
- Hot retention: 14 days (never deletes the last remaining dump).
- Restore only onto an isolated database name. Never restore onto `hasem`.
- Prove restore with `php artisan backup:drill` on MariaDB/MySQL, not SQLite.

## Database migrations

Two files share timestamp `2026_09_01_120000`:

- `2026_09_01_120000_create_pos_customer_sessions_table.php`
- `2026_09_01_120000_hasim_mobile_api_foundation.php`

Do **not** rename them on environments that already ran migrations. Laravel records the full filename, so both can apply. Treat a rename as a history rewrite.

## Observability

- [ ] Log channel that ships to your aggregator
- [ ] Alert on queue failures, payment webhook failures, and 5xx rates
- [ ] Sentry is optional; do not send tokens, passwords, or card data
