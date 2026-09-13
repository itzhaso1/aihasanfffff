# DEPLOYMENT-AUDIT

Audit date: 2026-09-13

---

## 1. What exists

| Item | Status | Evidence |
| --- | --- | --- |
| Production checklist | Present | `docs/PRODUCTION.md` |
| Linux/Nginx sketch | Present | `docs/deployment.md` |
| Mobile deploy notes | Present | `docs/deployment-mobile.md` |
| Docker | **Missing** | no Dockerfile |
| First-party CI | **Missing in tree** | no `.github/workflows` |
| SSL | Documented Certbot | not automated in repo |
| Redis | Recommended, not default | PRODUCTION.md |
| Workers / cron | Documented | `routes/console.php` schedule |
| Rollback | **Missing** | no deploy script |
| Env example | Present | `.env.example` (debug-friendly) |
| Health | `/up` | `bootstrap/app.php` |
| Git remote | **This folder is not a git repo** | cannot tag/release from here |

---

## 2. Scheduler (must run)

From `routes/console.php`:

- `finance:invoices:refresh-payment-status` hourly
- `finance:invoices:send-reminders` daily 08:00
- `finance:billing-schedules:generate` daily 01:15
- `ExpireStoriesJob` hourly
- `subscriptions:expire-trials|process-renewals|process-dunning` hourly

All `withoutOverlapping`. If cron is down: overdue statuses stale, subscriptions not expired, stories linger, **invoices generated late or not at all**.

---

## 3. Findings

### AUD-OPS-001
- Severity: Critical
- Title: No deploy artifact, Docker, or CI in the workspace
- Impact: Cannot reproduce production; “works on my machine”
- Priority: P0

### AUD-OPS-002
- Severity: Critical
- Title: Database queue/session will collapse under POS + WhatsApp + billing
- Location: local defaults
- Impact: Lost jobs = lost money/messages
- Priority: P0

### AUD-OPS-003
- Severity: High
- Title: No Laravel backup/restore drill
- Impact: Unrecoverable tenant finance
- Priority: P0

### AUD-OPS-004
- Severity: High
- Title: Observability is log-only
- Evidence: no Sentry/Prometheus in composer
- Impact: Cannot answer “which workspace / invoice failed”
- Priority: P1

UNKNOWN — Requires runtime verification: any live host, TLS grade, worker count, disk for `storage/app/private`.
