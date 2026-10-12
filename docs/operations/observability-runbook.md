# Observability, alerting, backups, DR, and load testing (Phase P5)

This runbook describes what the application implements in code versus what operators must configure on the host (Supervisor, Nginx, MySQL, Redis, off-site backups, external alerting).

## Monitoring architecture

| Layer | Implementation | Notes |
| --- | --- | --- |
| Health | `RuntimeHealthService` + `OperationalHealthService` | DB, Redis, queue driver, Reverb config, disk, storage probe, queue depths, failed jobs |
| CLI | `php artisan ops:health`, `php artisan runtime:check` | Exit non-zero when unhealthy (strict in production) |
| Platform API | `GET /platform/ops/health`, `GET /platform/ops/metrics` | **Platform admin session only** — no public exposure |
| Structured logs | `OpsLogger`, existing domain events (`inbox.*`, `ai.reply.*`, `webhook.*`) | Forbidden keys stripped; no tokens/passwords/payloads |
| Correlation | `AssignCorrelationId` middleware on Meta/WhatsApp webhooks; optional job field | Response header `X-Correlation-Id` (configurable) |
| Metrics store | Redis keys `ops:cnt:YmdH:*`, `ops:timing:YmdHi:*` | Best-effort; failures never break requests/jobs |
| Alerts | `php artisan ops:alerts:evaluate` (scheduled every 5 minutes) | Exit code 1 → wire to pager/cron alert |
| Queue workers | **Supervisor** (P0) — **not Laravel Horizon** | Horizon is not installed (Laravel 13 monolith uses dedicated queues) |
| Load test | `php artisan ops:load-test:synthetic-inbound` | **Refuses `APP_ENV=production`** |

## Horizon status

**Not installed.** Composer does not include `laravel/horizon`. Production uses Supervisor programs per queue (`inbound`, `realtime`, `ai`, `outbound`, `media`, `default`). Do not expose a Horizon dashboard publicly.

Recommended conservative worker counts (adjust after staging load tests and CPU/RAM):

| Pool | Starting processes | Rationale |
| --- | ---: | --- |
| inbound | 2–4 | Protect customer message ingestion SLO |
| realtime | 1–2 | Broadcast fan-out; keep separate from AI |
| ai | 1–2 | CPU/API bound; avoid starving inbound |
| outbound | 2–3 | Provider rate limits |
| media | 1–2 | IO bound |
| default | 1 | General jobs |

## Health checks

- **Application**: Laravel `/up` (framework) + `ops:health` / platform JSON.
- **Database / Redis / Queue driver**: via `RuntimeHealthService`.
- **Storage**: write/delete probe on configured media disk.
- **Disk**: free space on `storage/` vs `OPS_ALERT_DISK_FREE_PERCENT`.
- **Queues**: depth per named queue vs `OPS_ALERT_*_DEPTH` thresholds.
- **Failed jobs**: count in last hour vs `OPS_ALERT_FAILED_JOBS_1H`.

Statuses: `healthy`, `degraded` (non-critical threshold breach), `unhealthy` (DB/Redis/production queue driver failure).

## Alerting (starting thresholds)

Configure via `.env` (see `.env.example`). Wire `ops:alerts:evaluate` exit code to your monitor (cron email, Grafana OnCall, etc.).

| Signal | Env / check | Default |
| --- | --- | --- |
| Inbound backlog | `OPS_ALERT_INBOUND_DEPTH` | 500 |
| Realtime backlog | `OPS_ALERT_REALTIME_DEPTH` | 200 |
| AI backlog | `OPS_ALERT_AI_DEPTH` | 300 |
| Outbound backlog | `OPS_ALERT_OUTBOUND_DEPTH` | 500 |
| Media backlog | `OPS_ALERT_MEDIA_DEPTH` | 200 |
| Failed jobs (1h) | `OPS_ALERT_FAILED_JOBS_1H` | 25 |
| Disk free % | `OPS_ALERT_DISK_FREE_PERCENT` | 10 |
| Webhook 5xx rate | `OPS_ALERT_WEBHOOK_5XX_RATE` | 5% (documented; extend external LB logs) |

Avoid paging on single transient failures; use sustained threshold breaches.

## Structured logging fields

Prefer: `workspace_id`, `conversation_id`, `message_id`, `webhook_event_id`, `provider`, `queue`, `duration_ms`, `status`, `correlation_id`, `event` (via `OpsLogger`).

Never log: access tokens, passwords, `APP_KEY`, raw Authorization headers, full webhook payloads, full customer message bodies, prompts.

## Correlation / trace

1. Webhook HTTP → `X-Correlation-Id` assigned or forwarded.
2. WhatsApp inbound job stores optional `correlationId` and restores `CorrelationContext` in the worker.
3. Related logs include `correlation_id` when context is set.

Internal IDs are not exposed to end customers via this header.

## Backups and DR

See [backup-restore-runbook.md](./backup-restore-runbook.md) for database/files commands.

| Item | Code | Operator |
| --- | --- | --- |
| DB schedule | `backup:database` every 6h | Ensure `BACKUP_*` disk writable; off-site sync |
| Verify latest | `backup:verify-latest` weekly (Mon 03:30) | Alert on failure |
| Restore drill | `backup:drill` | Run on staging; not production |
| Media / object storage | Documented below | S3 versioning + lifecycle; not in mysqldump |

### Media backup strategy

- **Database backup does not include object storage.**
- Production `MEDIA_DISK=s3`: enable bucket versioning, cross-region replication or periodic sync to cold storage (operator-owned).
- Local disk: include `backup:files` in schedule if not using S3; replicate off-site.
- Retention and delete recovery follow cloud provider lifecycle policies.

### Proposed RPO / RTO (owner must confirm)

| Asset | Proposed RPO | Proposed RTO | Owner decision |
| --- | --- | --- | --- |
| Database | 6 hours (backup interval) | 2–4 hours | Business |
| Media | 24 hours (replication lag) | 4–8 hours | Business |
| Application code | Deploy artifact / git | 30 minutes | Engineering |
| Queue state | Ephemeral (Redis) | N/A — replay from webhooks/failed_jobs | Engineering |

Label: **proposed** until product owner signs off.

### Restore procedure (staging only)

1. `php artisan backup:restore` to an isolated restore database (see backup runbook).
2. Restore object storage prefix or bucket snapshot into staging bucket.
3. Copy non-secret config from production `.env` template; **do not copy production secrets into git**.
4. Start Redis, Reverb, Supervisor workers (`inbound` first).
5. Run migrations only if restore point requires it (prefer restore matching schema).
6. Validate workspaces, conversations, messages, media URLs, queue depths, Reverb health.
7. Validate provider connection rows exist (no token values in logs).

Do not call backups “verified” until `backup:verify-latest` or `backup:drill` succeeds.

## Load testing (staging / local only)

Command:

```bash
php artisan ops:load-test:synthetic-inbound --workspace=ID --count=50
php artisan ops:load-test:synthetic-inbound --async --count=200
```

Scenarios to run manually (document results in your capacity log):

| ID | Description |
| --- | --- |
| A | 1 msg/s sustained |
| B | 5 msg/s |
| C | 20 msg/s |
| D | 10× burst 60s |
| E | 100% duplicate replay |
| F | Multi-workspace |
| G | AI disabled |
| H | AI enabled |
| I | Simulated outbound delay (throttle workers) |
| J | Realtime unavailable (stop Reverb worker) |

Measure: webhook accept latency (provider path), sync persist latency (load command p50/p95/p99), queue depths, `ops:health`, host CPU/RAM, Redis/MySQL metrics from host tools.

**Do not claim production capacity without staging measurements.**

## Failure / chaos tests (staging)

Simulate: Redis down, DB connection refused, AI provider errors, outbound 429, worker kill, Reverb stopped. Verify: messages persist, failed jobs visible, retries behave, no silent loss.

## Retention

| Data | Policy |
| --- | --- |
| `webhook_events` (processed) | `ops:retention:webhook-events` daily, default 30 days |
| `failed_jobs` | Laravel default; monitor via alerts |
| Application logs | Host logrotate |
| AI logs (`ai_logs`) | Optional `OPS_RETAIN_AI_LOGS_DAYS` (not auto-deleted unless configured later) |
| Messages | No bulk delete in P5 — document archival for P6+ |

## Operations commands (safe restart)

Replace paths with your deployment root.

```bash
# Health
php artisan ops:health --json
php artisan runtime:check

# Workers (Supervisor — example names from deploy docs)
sudo supervisorctl restart hasem-inbound:*
sudo supervisorctl restart hasem-realtime:*
sudo supervisorctl restart hasem-ai:*
sudo supervisorctl restart hasem-outbound:*
sudo supervisorctl restart hasem-media:*

# Reverb (if used)
sudo supervisorctl restart hasem-reverb

# Queue backlog diagnosis
php artisan queue:monitor inbound realtime ai outbound media
php artisan ops:health

# Failed jobs
php artisan queue:failed
```

## Production configuration required

- Redis for queues and metrics (production strict health).
- Supervisor programs for each queue; conservative concurrency.
- Off-site backup replication and alert on `backup:verify-latest` failure.
- External monitoring on `ops:alerts:evaluate` exit code and `/platform/ops/health` (authenticated).
- Nginx/PHP-FPM/MySQL/Redis monitoring on host (not fully in-app).

## Deferred to P6

- Full webhook 5xx rate aggregation in-app, PHP-FPM saturation metrics, client-side realtime receipt latency, automated chaos suite, long-term message archival jobs.
