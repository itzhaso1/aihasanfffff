# Production Operations Runbook

## Monitoring

- **Application logs**: `storage/logs/laravel.log`
- **Queue failures**: `failed_jobs` table + `php artisan queue:failed`
- **Webhook events**: `webhook_events` table (`status`, `failed_reason`, `processed_at`)
- **AI usage/events**: `ai_logs` table
- **Audit trail**: `audit_logs` table
- **Health check**: `GET /up`

## Queue Workers

First-party runtime: `docs/operations/production-runtime-runbook.md`.

```bash
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=5 --timeout=200
php artisan runtime:check --production-strict
```

Supervisor template: `deploy/supervisor/hasem-worker.conf` (auto-restart). After deploy: `php artisan queue:restart`.

## Backups

First-party commands and the restore drill live in `docs/operations/backup-restore-runbook.md`.

### Database
- Frequency: every 6 hours (`backup:database` via Laravel scheduler + host cron `schedule:run`)
- Retention: 14 days hot on the private `backups` disk
- 90 days cold / off-site: operator-managed (not implemented in-repo)
- Verify restore with `php artisan backup:drill` on an isolated database

### Files
- DB dump does **not** include `storage/app/private` (KYC / private attachments)
- Use `php artisan backup:files` for that tree (excludes `backups/`)
- `storage/app/public` logos/attachments are a separate snapshot

### Restore Strategy
1. Restore DB snapshot onto an **isolated** database — never onto `hasem`.
2. Restore private-file archive matching or nearest to the DB timestamp.
3. Point a staging `DB_DATABASE` at the restored name and run smoke checks:
   - Login
   - Workspace access
   - Order + payment flow
   - WhatsApp webhook endpoint reachability

## Security Checklist

- Set all provider credentials in `.env`, never in code.
- Enforce HTTPS and secure cookies.
- Rotate webhook secrets and API keys periodically.
- Restrict file upload MIME and size (implemented for product images).
- Enable central error tracking (Sentry/Bugsnag) before production launch.

