# Security and Tests — HASEM Discovery Audit

Static review only. **No exploitation, no production access, no test execution.**

Prior audit artifacts exist under `docs/audit/` (P0/P1 plans, SECURITY-AUDIT.md) — this report summarizes **code-evidenced** controls in the current tree and gaps.

---

## 1. Authentication & sessions

| Finding | Status | Evidence |
| --- | --- | --- |
| Web session auth (Breeze) | Implemented | `routes/auth.php` |
| API Sanctum tokens | Implemented | `config/auth.php`, mobile/legacy API groups |
| Platform admin isolated guard | Implemented | `routes/platform.php`, `Platform\AuthController` |
| Mobile 401 JSON message | Implemented | `bootstrap/app.php` exception render L87–93 |
| CSRF on web forms | Laravel default; exceptions for webhooks | `bootstrap/app.php` L45–49 |

**Not verified:** Session cookie flags in production (`SESSION_*` names only — values not read).

---

## 2. Tenancy & IDOR

| Finding | Status | Evidence |
| --- | --- | --- |
| Global scope on tenant models | Implemented | `WorkspaceScopedModel` |
| Route binding without workspace → null/404 | Implemented | `WorkspaceScopedModel::resolveRouteBinding` L24–26 |
| Dedicated cross-workspace tests | Implemented (tests exist) | `tests/Feature/Feature/Security/CrossWorkspaceIdorTest.php` |
| Workspace resolution from header/session | Implemented | `ResolveWorkspaceContext`, `WorkspaceResolverService` |

**Confidence:** High for patterns; **runtime** IDOR on every new endpoint — requires per-route review.

---

## 3. Authorization (RBAC)

- Spatie permission with team = workspace id
- Feature gates: `EnsureFeatureAccess` + plan entitlements
- Worker capabilities: `WorkerEngagementAuthorization` path in feature middleware L31–36
- Finance elevation (manager = finance) — documented as **changed** in P0-5 audit; finance controllers **not in tree** to re-verify

---

## 4. Mass assignment & policies

- Models use `Fillable` attributes (PHP 8 attributes pattern e.g. `PlatformAdmin`)
- Policies exist for some domains (conversation handoff `@can` in inbox blades)
- **Partial coverage:** Not all controllers audited for `$request->validated()` vs `$request->all()`

---

## 5. CORS / security headers

- `SecurityHeaders` middleware appended globally — `bootstrap/app.php` L52
- CORS config in Laravel — **not fully read** in audit

---

## 6. File upload & downloads

- Message attachments: `MessageAttachmentController`, channel media jobs
- Email attachments: scoped migration `scope_email_attachments_recipients_and_push_tokens`
- Merchant/worker document download routes on platform admin with authorized controllers

**Risk:** Attachment download must always assert workspace + conversation ownership — tests exist for workspace attachment isolation (`WorkspaceInboxTest`).

---

## 7. Webhooks

- CSRF disabled for webhook paths (required for external POST)
- Jobs: `ProcessPaymentWebhook`, `ProcessSubscriptionWebhook`, channel inbound jobs
- Idempotency: webhook event uniqueness per workspace (migration)

**Prior audit:** P0-7 webhook fail-closed secret — see `docs/audit/P0-7-WEBHOOK-FAIL-CLOSED-SECRET.md` (not re-run).

---

## 8. AI security boundary

| Topic | Evidence |
| --- | --- |
| Platform model allow-list | `PlatformAiConfiguration::assertAllowedPair` |
| Tool argument size limits | `config/ai.php` inbox_agent.security |
| No finance writes from inbox AI | Master spec rule; finance AI separate schema |
| Prompt grounding | Chat catalog retrieval + redaction in settings |
| Job uniqueness | `ProcessAIResponse` ShouldBeUnique |

**Risk:** Platform admin selects model outside allow-list → runtime exceptions (observed class of bug in production logs — **config operational risk**, not tenant IDOR).

Prior: `docs/audit/P1-B-AI-SECURITY-PRIVACY-COST.md`.

---

## 9. Rate limiting

- Throttles on login (platform, mobile), mobile writes, legacy API
- **Evidence:** `routes/platform.php` L30; mobile route middleware attributes

---

## 10. Secrets handling

- PHPUnit sets dummy `PLATFORM_ADMIN_PASSWORD`, empty AI keys — `phpunit.xml` L37–39
- Audit rule: **no `.env` values copied**
- Log redaction helpers referenced (e.g. webhook sanitizer in PaymentService imports)

---

## 11. Queue tenant context

- Jobs often accept `workspaceId` (e.g. `ProcessAIResponse` constructor L37–40) and use `WorkspaceContext::runFor`
- **Risk:** Jobs missing workspace context could leak or fail — spot-check only, not exhaustive

---

## Security findings summary (this pass)

| ID | Severity | Finding | Confidence |
| --- | --- | --- | --- |
| S1 | **High** | Deploy tree may omit finance/cashier routes while exposing full schema | High (structural) |
| S2 | **High** | Cross-tenant access relies on discipline on every new route/model | Medium (mitigated by tests + base model) |
| S3 | **Medium** | Commerce payments disabled but orders creatable — business logic abuse (draft spam) | Medium |
| S4 | **Medium** | Webhook endpoints without CSRF — require signature verification (verify per handler) | Medium |
| S5 | **Medium** | AI tool injection / exfil — bounded by tool security config; not red-teamed | Low-Medium |
| S6 | **Low** | Platform AI misconfiguration causes DoS on AI-heavy pages | Observed in ops |

**Recommended investigation (no auto-fix):** Full endpoint matrix pass using existing `docs/audit/SECURITY_ENDPOINT_MATRIX.md` against **complete** route list from production artifact.

---

## 12. Tests & CI

| Item | Value |
| --- | --- |
| Framework | PHPUnit 12 (`composer.json` require-dev) |
| Config | `phpunit.xml` — SQLite `:memory:` |
| Test files | **~293** `*Test.php` files (count only) |
| CI | `.github/workflows/ci.yml` — pint, phpunit jobs |
| Critical suite | `phpunit.critical.xml` referenced in composer scripts |
| MariaDB job | `test:ci-mariadb` script exists |

### Coverage themes (from filenames / docs)

- Cross-workspace IDOR
- Inbox, handoff, worker boundaries
- Chat catalog search + AI tools
- Mobile API feature gates
- Commerce cart validation
- Billing lifecycle (referenced)
- Finance core (tests exist — **may fail if finance code missing**)

### Test execution this audit

**Not run.** Reasons: user rules forbid destructive/production commands; environment had incomplete dev tooling in one session (`php artisan test` undefined when dev deps incomplete).

**Do not claim tests pass.**

### High-risk missing tests (inferred)

- End-to-end commerce payment when enabled (currently blocked)
- Full finance API regression (if restored to tree)
- Cashier offline sync conflict (if cashier app restored)
- Gmail OAuth if implemented later

---

## 13. Operational readiness (static)

| Requirement | Notes |
| --- | --- |
| Redis | Predis in composer; queue often Redis in prod docs — **Unknown here** |
| Scheduler | `routes/console.php` — needs cron |
| Reverb / broadcasting | Laravel Reverb package present |
| Storage | Attachment paths use Laravel storage — disk config not audited |
| Backups | Scheduled `backup:database` command |

**No tests were executed. No code was modified.**
