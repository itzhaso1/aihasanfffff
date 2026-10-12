# SECURITY-AUDIT

Audit date: 2026-09-13  
Method: static forensic review (OWASP-aligned). No exploits were run. No secrets are printed.

---

## 1. Executive security view

The platform has **real server-side authorization** in many money and tenant paths (tests exist for OTP, roles, inventory, API keys, workspace isolation). It is **not** “UI-only security.”

It is also **not** production-hardened as a packaged system: missing first-party CI in this tree, local `.env` present, public unauthenticated AI, webhook CSRF exceptions, Sanctum global expiration null, and a tenancy scope that no-ops without context.

---

## 2. SECRET DETECTED

Do not treat the following as “no secrets exist.” Values are **not** shown.

**SECRET DETECTED**  
Path: `D:\projects\aihasan\.env`  
Type: Local environment file (APP_KEY, DB, mail, OAuth, AI, WhatsApp, payment webhook secrets typically live here)  
Severity: High if this file is copied, synced, committed, or shared  
Rotation required: YES (if this file has ever left the operator’s machine)

**SECRET DETECTED (default credential in repo)**  
Path: `D:\projects\aihasan\.env.example`  
Type: Documented default `PLATFORM_ADMIN_PASSWORD=password`  
Severity: Medium (example) / Critical if used in any live environment  
Rotation required: YES if that default was ever deployed

`.env.example` also lists empty placeholders for: `RESEND_API_KEY`, `GOOGLE_CLIENT_SECRET`, `FACEBOOK_CLIENT_SECRET`, `GEMINI_API_KEY`, `OPENAI_API_KEY`, `WHATSAPP_APP_SECRET`, `META_APP_SECRET`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `SUBSCRIPTION_WEBHOOK_SECRET`, `AWS_SECRET_ACCESS_KEY`. Those placeholders are expected. Live values must not be committed.

No live `sk_live` / AWS access-key material was found in first-party PHP/Dart/docs (vendor RSA “BEGIN PRIVATE KEY” strings are library samples).

---

## 3. Findings

### AUD-SEC-001
- Category: Security / Multi-tenancy
- Severity: Critical
- Title: Workspace global scope does not filter when context is unset
- Location: `app/Models/WorkspaceScopedModel.php` (global scope)
- Evidence: If `WorkspaceContext::workspaceId()` is null, the `workspace` scope adds **no** `WHERE workspace_id`. Creating is allowed when the incoming model already has `workspace_id`. Updating skips checks when context is null. Platform admin skip on update.
- Impact: Any job, artisan command, webhook, or mis-ordered middleware that queries scoped models without binding a workspace can read/write other tenants’ rows.
- Exploitability: Medium (needs a code path that forgets context; several unscoped related models exist)
- Recommendation: Fail closed: if a request/user exists, require context or throw; add `withoutWorkspaceScope` explicitly for platform jobs.
- Effort: M
- Priority: P0

### AUD-SEC-002
- Category: Security
- Severity: High
- Title: Public unauthenticated assistant can spend AI quota / leak configured knowledge
- Location: `app/Http/Controllers/AssistantController.php`, `POST /assistant/chat` in `routes/web.php`
- Evidence: No auth. Limits: 8/min, 40/day, 800 chars. `docs/security.md` marks this ACCEPTED RISK. `WebsiteAssistantService` can call Gemini/OpenAI when keys exist.
- Impact: Cost abuse, prompt injection against published-site context, model/key spend
- Exploitability: High
- Recommendation: Keep rate limits; bind only published website; never include stock/PII; budget cap per IP; WAF
- Effort: S
- Priority: P1

### AUD-SEC-003
- Category: Security
- Severity: High
- Title: CSRF disabled for inbound webhooks
- Location: `bootstrap/app.php` `validateCsrfTokens(except: ['webhooks/resend', 'whatsapp-webhook'])`
- Evidence: Necessary for third-party POSTs, but security depends entirely on signature verification
- Impact: Forged inbound messages / email events if signature check is weak or secret empty
- Exploitability: Medium (UNKNOWN without runtime secrets)
- Recommendation: Reject unsigned webhooks when secret configured; fail closed in production
- Effort: S
- Priority: P0

### AUD-SEC-004
- Category: Security
- Severity: High
- Title: Sanctum global token expiration is null
- Location: `config/sanctum.php` `'expiration' => null`
- Evidence: Relies on per-token `expires_at` (30d API / 60d mobile). Stolen token remains valid until that date or logout.
- Impact: Long-lived bearer tokens on mobile/cashier devices
- Exploitability: Medium
- Recommendation: Keep per-token TTL; add revocation on password change / device loss; consider refresh rotation
- Effort: M
- Priority: P1

### AUD-SEC-005
- Category: Security
- Severity: High
- Title: Manager membership equals full finance access
- Location: `FinanceBaseController::authorizeFinance`, `AuthorizesFinanceApi::isElevatedFinanceMember`
- Evidence: owner/admin/**manager** bypass Spatie permission checks
- Impact: Privilege expansion: a “manager” can hit finance.manage-equivalent API map (`financePermissionMap` ORs `$elevated`)
- Exploitability: High (invite a manager)
- Recommendation: Stop collapsing all `financePermissionMap` keys through `$elevated`; elevate view-only or named abilities
- Effort: M
- Priority: P0

### AUD-SEC-006
- Category: Security
- Severity: Medium
- Title: Public QR menu can create orders without login
- Location: `routes/web.php` `/menu/{workspace:slug}`
- Evidence: Throttle 30/min; slug is public identifier
- Impact: Spam orders, kitchen noise, possible inventory/order pollution
- Exploitability: High if slugs are guessable
- Recommendation: Feature flag, CAPTCHA, close menu, rate by IP+slug
- Effort: S
- Priority: P2

### AUD-SEC-007
- Category: Security
- Severity: Medium
- Title: AI logs store full prompts and provider raw output
- Location: `app/Services/AI/AIService.php` `AiLog::create` `input_payload` / `output_payload`
- Impact: PII and conversation content in DB; backup/export leakage
- Exploitability: Low (needs DB access) / High (insider)
- Recommendation: Redact; retain IDs not bodies; encrypt
- Effort: M
- Priority: P1

### AUD-SEC-008
- Category: Security
- Severity: Medium
- Title: CORS is closed by default but misconfiguration is one env var away
- Location: `config/cors.php` `CORS_ALLOWED_ORIGINS`
- Evidence: No `*`. `supports_credentials` default false
- Impact: If production sets `*` or wide origins with credentials, token theft via browser
- Exploitability: Low unless misconfigured
- Recommendation: Keep empty in cookie-less Flutter; never `*`
- Effort: S
- Priority: P2

### AUD-SEC-009
- Category: Security
- Severity: Medium
- Title: First-party CI workflow missing in this tree
- Location: expected `.github/workflows/ci.yml` (claimed in `docs/HASEM-POST-REPAIR-AUDIT.md`)
- Evidence: Glob of project `.github/workflows` found **none** (only vendor/node_modules)
- Impact: Security tests can regress silently
- Exploitability: n/a
- Recommendation: Restore CI that fails on tests; do not make Pint the only gate
- Effort: S
- Priority: P0

### AUD-SEC-010
- Category: Security
- Severity: Low
- Title: No PHPStan / Larastan
- Location: `composer.json` require-dev
- Evidence: pint, phpunit, collision — no phpstan
- Impact: Authz typos and dead branches survive
- Recommendation: Incremental PHPStan
- Effort: L
- Priority: P3

---

## 4. OWASP checklist (static)

| Item | Status | Evidence |
| --- | --- | --- |
| Broken access control | Partial remaining | Elevation + unscoped models + context-null scope |
| Auth flaws | Hardened vs earlier audit | OTP tests, throttle, Socialite |
| Injection (SQL) | Generally Eloquent | Review raw `selectRaw` / `DB::table` (invoice integrity command) |
| XSS | Blade default escape | UNKNOWN for `{!! !!}` usage at scale |
| CSRF | On for web; except webhooks | `bootstrap/app.php` |
| SSRF | Possible via Guzzle to Graph/OpenAI | Providers use fixed URLs — lower risk |
| Path traversal | SecureUpload random names | Need all upload call sites to use it |
| File upload | Central helper | `SecureUpload` MIME+ext+size+no SVG |
| Command injection | Not seen in first-party | UNKNOWN artisan user input |
| Sensitive data | AiLog, EmailLog, .env | See PRIVACY-AUDIT |
| Security misconfiguration | Debug defaults in example | `docs/PRODUCTION.md` |
| Insecure deserialization | Laravel signed cookies | Keep APP_KEY secret |
| Open redirects | Socialite callback | UNKNOWN all redirect() targets |
| Clickjacking / CSP | Not audited in headers | UNKNOWN — Requires runtime |
| Session | Documented secure cookie flags for prod | Not verified live |

---

## 5. What is good

- OTP generic responses and throttles (`docs/security.md`, `OtpProtectionTest`)
- API keys stored as hash (`docs/security.md`)
- Merchant documents on private disk
- Role assignment hierarchy (`WorkspaceAccess::canAssignRole`)
- Isolation tests: `tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php`
- CORS default deny-all origins

## 6. Runtime not performed

UNKNOWN — Requires runtime verification: cookie flags, CSP, HSTS, TLS, webhook signatures with empty secrets, APP_DEBUG on any deployed host.
