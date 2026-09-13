# HASIM SMART — التقرير الكامل (ملف واحد)

**التاريخ:** 2026-09-13  
**المسار:** `D:\projects\aihasan`  
**النوع:** Forensic / Technical / Security / Product / Architecture  
**الحكم:** NOT READY للإطلاق العام بأموال حقيقية — الدرجة **61/100**

هذا الملف يجمع كل تقارير التدقيق في مكان واحد. لم يُعدَّل كود المنتج.

## فهرس

1. [الخلاصة التنفيذية](#master-audit--hasim-smart--hasem)
2. [خريطة المشروع](#project-map--hasim-smart--hasem)
3. [العمارة](#architecture-audit)
4. [المنتج والميزات](#product-audit)
5. [الأمن](#security-audit)
6. [المصادقة](#auth-audit)
7. [عزل المؤسسات](#multi-tenancy-audit)
8. [قاعدة البيانات](#database-audit)
9. [المالية وزاتكا](#finance-audit)
10. [المحادثات](#chat-audit)
11. [نقاط البيع](#pos-audit)
12. [الذكاء الاصطناعي](#ai-audit)
13. [الواجهات](#frontend-audit)
14. [تجربة المستخدم](#ux-ui-audit)
15. [الموبايل](#mobile-audit)
16. [سطح المكتب](#desktop-audit)
17. [الأداء](#performance-audit)
18. [الاختبارات](#qa-audit)
19. [النشر](#deployment-audit)
20. [الخصوصية](#privacy-audit)
21. [الدين التقني](#tech-debt)
22. [خارطة الطريق](#roadmap)

---



---


# MASTER-AUDIT — HASIM SMART / HASEM

**Date:** 2026-09-13  
**Workspace:** `D:\projects\aihasan`  
**Method:** Static forensic review of code, routes, models, migrations, Flutter clients, and first-party docs.  
**Rule honored:** No fixes, no refactors, no migrations, no dependency updates, no env changes.

Companion files live in `docs/audit/`. Interactive summary: see the Cursor canvas linked from the chat.

---

## 1. Executive Summary

HASIM SMART is **one Laravel 13 modular monolith** (PHP 8.3) plus **three Flutter clients** (chat, finance, cashier) and a Blade web/admin surface. There is **one backend** and **one canonical database**. Flutter local SQLite/Hive stores are **caches / offline POS**, not a second company.

The domain core is unusually serious for an SMB suite: workspace tenancy, Spatie teams, integer `Money`, posted-journal immutability, invoice tax engine, cashier sync, and a large PHPUnit suite. That is the good news.

The launch-blocking news: **tenant scope fails open without context**, **managers inherit all finance abilities**, **POS/offline tax can be client-supplied**, **legal pages are drafts**, **ZATCA is a harness not a clearance integration**, **SaaS charges are still a local billing provider**, **this tree has no first-party CI and is not a git repository**, and **Laravel backups/observability are not implemented**.

**Verdict: NOT READY for public multi-tenant production with real customer money.**  
**Conditional** only for a closed beta: trusted tenants, `APP_DEBUG=false`, Redis+workers+cron, no ZATCA/compliance marketing, WhatsApp optional, HyperPay sandbox off.

Prior score 73/100 (2026-09-02) assumed a CI file and a green 284-test run. Those are **not re-verified** here; CI is **missing from this tree**. This audit’s overall score is **61/100**.

---

## 2. What is HASIM SMART

Arabic-first **workspace SaaS** for running a business’s **inbox, invoices/ledger, and cashier** on one tenant (`workspaces`).  

- **SaaS / Web:** Laravel Blade (`/`, `/workspace/*`, `/platform`, legal).  
- **Mobile:** `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`.  
- **Desktop:** same Flutter apps’ `windows/` runners — not a separate native product.  
- **Core platform:** `app/Services/*` + `WorkspaceScopedModel` + shared MySQL.  
- **Business rules:** PHP services. Flutter finance must not invent tax/GL. Cashier **does** local pricing for offline (documented tension).

See `PROJECT-MAP.md` and `PRODUCT-AUDIT.md`.

---

## 3. Architecture

```text
Flutter Chat / Finance / Cashier / Browser
        │  Sanctum or session
        ▼
Laravel routes (web, api, mobile, cashier, finance-api, platform)
        │  workspace.resolve + member + feature
        ▼
Controllers → Services/{Finance,Pos,Payment,WhatsApp,AI,Subscription}
        │
        ▼
WorkspaceScopedModel → ONE database
        │
        ├── Queue jobs (WhatsApp, email, payments, AI, billing)
        ├── Scheduler (invoice status, reminders, subscriptions)
        └── External: Meta, Resend, Stripe/HyperPay/local, OpenAI/Gemini
```

Details: `ARCHITECTURE-AUDIT.md`.

---

## 4. Product Map

```text
HASIM SMART
├── Public web (landing, legal drafts, assistant, QR menu)
├── Auth (session, OTP, Google/Facebook, Sanctum ×4 families)
├── Web tenant (inbox, POS, finance Blade, CRM, billing)
├── Platform admin
├── Chat app          → /api/mobile/v1
├── Finance app       → /api/finance/v1
├── POS / cashier app → /api/cashier/v1 + /api/pos/sync
├── Shared services   → customers, products, invoices, ledger, WhatsApp
└── E-invoice kit     → QR/XML/stamp foundation (not certified)
```

---

## 5. Current State

| Area | State |
| --- | --- |
| Codebase inventory | ~209 controllers, ~163 services, ~120 models, 110 migrations, 13 jobs, 10 events, 2 listeners, 12 policies, 0 PHP repos, 317 Blade, 162 PHP test files, 84/49/116 Dart lib files |
| Website builder / appointments | Retired (2026-09-12 migrations) |
| Quotes | Implemented (API + models) — older spec lines that say “not first-class” are stale |
| WhatsApp / email | Built; live traffic UNKNOWN |
| Instagram/Messenger | UI only |
| Finance ledger | Real double-entry lite |
| ZATCA | Foundation / tests only |
| Billing | Local provider |
| Ops | Docs yes; Docker/CI/backup no |

Exact `route:list` count: UNKNOWN — Requires `php artisan route:list`.

---

## 6. Critical Risks

1. Cross-tenant query if workspace context unset (`WorkspaceScopedModel`).  
2. Double POS sale / client tax on sync.  
3. Manager role = full finance map.  
4. Lost queue jobs (database driver, no workers).  
5. No database backup.  
6. Public AI cost + prompt injection.  
7. Legal/PDPL unreadiness.  
8. Claiming ZATCA/compliance without clearance.  
9. Webhook accepted without secret.  
10. Stolen 60-day Sanctum token.

---

## 7. Security

See `SECURITY-AUDIT.md`, `AUTH-AUDIT.md`.

**SECRET DETECTED** — Path: `D:\projects\aihasan\.env` — Type: local env — Severity: High if shared — Rotation required: YES  

**SECRET DETECTED** — Path: `.env.example` `PLATFORM_ADMIN_PASSWORD=password` — Type: default credential — Rotation required: YES if ever deployed.

Good: hashed API keys, OTP hardening, CORS deny-by-default, private KYC disk, role hierarchy, isolation tests.

---

## 8. Finance Integrity

Hybrid invoicing + ledger. **Ledger P&L is SoT; dashboard cards may lie.**  
POS tax is a second engine and may trust the client.  
`Money` is integer — `round()` still returns float.  
Posted journals immutable — do not “fix balances by hand.”  
ZATCA: **not compliant** (foundation only).

See `FINANCE-AUDIT.md`.

---

## 9. Multi-tenancy

Design is correct and mostly enforced. Residual holes: fail-open scope, unscoped email/push models, `withoutGlobalScopes()` copies, public slug menu, platform admin god mode.

See `MULTI-TENANCY-AUDIT.md`.

---

## 10. Cross-app integration

Online: **shared DB** (good SoT).  
Offline: cashier + chat local DBs (conflict/duplication).  
Customers/products/invoices have **multiple writers**.  
Chat does not create finance invoices. POS can create finance-adjacent cashier invoices. Finance does not operate WhatsApp.

See `PRODUCT-AUDIT.md` §5, `POS-AUDIT.md`, `CHAT-AUDIT.md`.

---

## 11. Missing Features

Instagram/Messenger/Telegram/TikTok backends; live subscription charge; live HyperPay split; official e-invoice clearance; warehouses/FIFO; IAS 7 cash flow; FX posting; 2FA; cookie consent; complete legal; Laravel backup; APM; Docker/CI; desktop auto-update; sales-order document; credit-limit hard block; data export/delete; EN parity. Full list: `PRODUCT-AUDIT.md` §4.

---

## 12. Technical Debt

God services intentionally not split (prior audit). Dual UIs. Dual catalogs. Dual tax. Stale docs. No PHPStan. Pint dirty. See `TECH-DEBT.md`.

---

## 13. Testing

Large PHP suite historically green; **not re-run today**. Flutter Windows env previously unusable. No E2E. No load test. CI missing. See `QA-AUDIT.md`.

---

## 14. Production Readiness

| Area | Score /100 | Note |
| --- | ---: | --- |
| Security | 68 | Hardening exists; fail-open scope + public AI |
| Architecture | 74 | Correct monolith; ops packaging weak |
| Finance | 72 | Real ledger; KPI/POS tax drift |
| Data integrity | 64 | Offline + dual documents |
| Auth | 76 | Solid session/OTP; long tokens |
| Authorization | 66 | Elevation too broad |
| Multi-tenancy | 78 | Best part of the system if context set |
| Performance | 58 | DB queue; unmeasured |
| UI | 70 | Feature-rich; three languages |
| Mobile | 72 | Real clients; token/offline risk |
| Desktop | 55 | Runners only; no updater |
| Testing | 70 | Breadth yes; CI/MySQL/E2E no |
| Deployment | 38 | Docs without machinery |
| Monitoring | 35 | Logs, no APM |
| Documentation | 80 | Excellent first-party docs; some stale |
| Compliance readiness | 42 | Legal placeholders; ZATCA harness |

**Overall: 61/100 — NOT READY**

---

## 15. Top 50 Fixes

Ranked by risk × impact, then effort.

1. Fail-closed workspace scope (P0)  
2. Restore CI + run full PHP suite (P0)  
3. Automated DB + private-disk backup/restore (P0)  
4. Redis + workers + cron in any live env (P0)  
5. Server-authoritative POS tax on sync (P0)  
6. Unique client_reference / reject duplicate push (P0)  
7. Narrow manager finance elevation (P0)  
8. Scope EmailLog and sibling unscoped tenant tables (P0)  
9. Complete or unpublish legal pages (P0)  
10. Webhook reject when secret missing (P0)  
11. Dashboard KPIs = ledger (P0)  
12. Never market ZATCA-certified (P0)  
13. Rotate `.env` if it left the machine (P0)  
14. Change platform admin default password (P1)  
15. Public AI budget / kill switch (P1)  
16. Hide Instagram/Messenger until real (P1)  
17. Unify product vs menu item (P1)  
18. Revoke tokens on password reset (P1)  
19. MySQL race tests for payments/stock (P1)  
20. Single tax engine (P1)  
21. WhatsApp health “cannot send” (P1)  
22. Redact AiLog payloads (P1)  
23. Encrypt chat local DB (P1)  
24. Desktop min-version / updater (P1)  
25. 2FA owners + platform (P1)  
26. Observability: request id + workspace id (P1)  
27. Alert on queue:failed and 5xx (P1)  
28. Signed cashier/finance builds (P1)  
29. Customer dedup across apps (P1)  
30. Confirm dialogs on reverse/cancel (P1)  
31. Feature-flag QR menu (P1)  
32. Job context helper for tenancy (P1)  
33. PHPStan baseline (P2)  
34. Membership enum vs accountant (P2)  
35. Pint baseline then blocking (P2)  
36. Blade dark or light-only statement (P2)  
37. Bank CSV import (P2)  
38. Report export CSV (P2)  
39. Shift close → treasury JE (P2)  
40. Cookie/consent banner (P2)  
41. Data subject delete/export (P2)  
42. Bound sync pagination (P2)  
43. Cache TB/P&L (P2)  
44. Remove leftover website/appointment tests (P2)  
45. Honest copilot copy (P2)  
46. Hide Gemini-missing reason (P2)  
47. Docker/staging compose (P2)  
48. EN locale for new finance screens (P3)  
49. Design-token cleanup (P3)  
50. OCR / forecasting — only after Phase 10 (P3)

---

## 16. Roadmap

Phases 0–10 are in `ROADMAP.md`: Security → Integrity → AuthZ → Finance → Integration → Stability → Perf → UX → Tests → Deploy → Polish.

---

## 17. What is good

- One product, one DB, one ledger — correct commercial architecture  
- `Money` + immutable journals + period guard  
- Tenant 404 convention and many isolation tests  
- OTP / API key / KYC / role hierarchy repairs still in code  
- Finance Flutter as a client (no local GL)  
- Cashier offline engineering is real (PIN, backup, sync)  
- Additive migrations; invoice statuses preserved  
- First-party documentation volume (`docs/*`)  

## 18. What is bad or dangerous

- Fail-open tenancy scope  
- Client tax / dual engines  
- Manager = CFO  
- Unscoped email/push models  
- Public AI + QR AI  
- Legal fiction  
- Ops fiction (CI/Docker/backup/APM)  
- Channel theater (IG/Messenger)  
- Dashboard vs books  

## 19. What must not change

- Do not split into multiple apps or databases  
- Do not drop `workspace_id` isolation or hide-nav-as-auth  
- Do not let Flutter finance recompute tax/GL  
- Do not let AI post/refund without a confirmed workflow  
- Do not rewrite invoice statuses or posted journals in place  
- Do not rename the duplicate `2026_09_01_120000` migrations on live DBs  
- Do not “fix” purchase invoices without inventory so they stop using 5900 (spec)  
- Keep Sanctum per-token TTL design unless replacing with refresh  
- Keep `SecureUpload` allow-lists (no SVG)  
- Keep merchant GMV KYC assertion  

---

## 20. Master findings table

| ID | Severity | Category | Finding | Location | Impact | Recommendation | Effort | Priority | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AUD-SEC-001 | Critical | Security | Scope fail-open | `WorkspaceScopedModel.php` | Cross-tenant read/write | Fail closed | M | P0 | Open |
| AUD-SEC-002 | High | Security | Public assistant | `AssistantController.php` | Cost / injection | Budget + bind site | S | P1 | Accepted risk |
| AUD-SEC-003 | High | Security | CSRF-except webhooks | `bootstrap/app.php` | Forged events | Fail closed unsigned | S | P0 | Open |
| AUD-SEC-004 | High | Security | 60d tokens | `config/sanctum.php` | Account takeover | Rotate / revoke | M | P1 | Open |
| AUD-SEC-005 | High | Security | Manager=finance | `AuthorizesFinanceApi.php` | Money mutations | Split elevation | M | P0 | Open |
| AUD-SEC-009 | High | Security | CI missing | `.github` absent | Silent regression | Restore CI | S | P0 | Open |
| AUD-TEN-002 | High | Tenancy | EmailLog unscoped | `app/Models/EmailLog.php` | PII leak | Scope | S | P0 | Open |
| AUD-FIN-001 | Critical | Finance | KPI ≠ P&L | dashboard / status doc | Wrong decisions | Ledger cards | M | P0 | Open |
| AUD-FIN-002 | Critical | Finance | Client tax trusted | `PosTaxCalculator` | VAT error | Recompute server | M | P0 | Open |
| AUD-FIN-003 | High | Finance | Two tax engines | Finance vs POS | Inconsistent totals | Unify | L | P1 | Open |
| AUD-POS-001 | Critical | POS | Duplicate offline sale | sync push | Double charge | Unique ref | L | P0 | Open |
| AUD-POS-002 | Critical | POS | Local pricing SoT | cashier `pricing_service.dart` | Ticket ≠ books | Server final | L | P0 | Open |
| AUD-DB-002 | High | Database | No Laravel backup | repo | Total loss | Automate + drill | M | P0 | Open |
| AUD-OPS-001 | Critical | Deploy | No Docker/CI/git | workspace | Irreproducible | Package | M | P0 | Open |
| AUD-PRV-001 | High | Privacy | Legal placeholders | `config/legal.php` | Regulatory | Fill or unpublish | S | P0 | Open |
| AUD-CHAT-001 | High | Chat | Fake channels | `ChannelController` | Trust | Hide | S | P1 | Open |
| AUD-AI-002 | High | AI | Full prompt logs | `AIService` | PII | Redact | S | P1 | Open |
| AUD-AUTH-003 | Medium | Auth | Role enum collapse | `WorkspaceAccess` | Wrong ACL | Migrate or relabel | M | P2 | Open |
| AUD-DESK-001 | High | Desktop | No auto-update | Flutter windows | Stale vulns | Signed channel | L | P1 | Open |

More IDs in the satellite files.

---

## 21. First 10 things to fix

1. Fail-closed tenancy  
2. CI + test run on this tree  
3. Backups  
4. Redis/workers/cron  
5. Server tax + sync idempotency  
6. Finance permission elevation  
7. Scope email/push models  
8. Legal pages  
9. Webhook secrets fail-closed  
10. Stop ZATCA/compliance claims  

---

## 22. Unknowns

Anything marked **UNKNOWN — Requires runtime verification** in satellite files, including: live `.env` values (not printed), MySQL locks, webhook signatures, WhatsApp delivery, Flutter store builds, browser dark mode, `php artisan test` on 2026-09-13, XSS `{!! !!}` sweep, and whether a production host even exists.



---


# PROJECT-MAP — HASIM SMART / HASEM

Audit date: 2026-09-13  
Scope: `D:\projects\aihasan`  
Rule: inventory from the workspace as it exists. No code was changed.

---

## 1. What this workspace is

One commercial product branded **حاسم / HASIM SMART / HASEM**.  
It is a **modular Laravel 13 monolith** (PHP 8.3) with **one shared database** and `workspace_id` tenancy. Flutter apps are **clients**, not separate backends.

Evidence: `AGENTS.md`, `docs/HASEM_MASTER_PLATFORM_SPEC.md`, `composer.json`, `routes/api.php`.

---

## 2. PROJECT INVENTORY

| Name | Path | Type | Framework | Language | Package manager | Entry point | Purpose | Dependencies (key) | Database | APIs | Authentication | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| HASEM Core (Laravel) | `D:\projects\aihasan` | SaaS backend + Blade web | Laravel 13 | PHP 8.3, Blade, JS (Vite) | Composer + npm | `public/index.php`, `bootstrap/app.php` | Identity, tenancy, CRM, inbox, finance, POS web, billing, AI, webhooks | laravel/framework ^13.17, sanctum, socialite, spatie/permission, guzzle, dompdf, ar-php | Shared (MySQL intended; SQLite in tests) | `/api`, `/api/mobile/v1`, `/api/cashier/v1`, `/api/finance/v1`, webhooks | Session (web) + Sanctum (API) + `platform_admin` | Core: partial-to-strong; production ops incomplete |
| Landing / legal | `resources/views/landing.blade.php`, `resources/views/legal/*` | Public web | Blade | PHP/HTML | same | `routes/web.php` `/` `/privacy` `/terms` `/cookies` `/acceptable-use` | Marketing + legal drafts | none extra | none | none | public | Incomplete legal placeholders (`config/legal.php`) |
| Web workspace (Blade) | `resources/views/workspace/*` | Tenant web UI | Blade + Alpine/Tailwind | PHP | same | `routes/web.php` `/workspace/*` | Inbox, POS web, finance web, products, employees | Vite/Tailwind/Alpine | shared | session HTML | session + workspace middleware | Feature-complete in many modules; Website Builder retired |
| Platform admin | `routes/platform.php` prefix `/platform` | Isolated admin | Blade | PHP | same | `EnsurePlatformAdmin` | Plans, merchant KYC, platform ops | Spatie + platform_admin guard | shared (global tables) | n/a | `platform_admin` | Present; default password documented in `.env.example` |
| Hasim Chat | `apps/hasim` | Mobile + Windows Flutter | Flutter 3 / Dart ^3.9 | Dart | pub | `lib/main.dart` | Inbox, WhatsApp/email/customers/stories | riverpod, go_router, dio, flutter_secure_storage, hive, drift, google_sign_in | Local Drift/Hive cache + Laravel | `/api/mobile/v1` | Sanctum + Google | Implemented client; channels beyond WhatsApp/email are UI-level |
| Hasim Finance | `apps/hasim_finance` | Mobile + Windows Flutter | Flutter | Dart | pub | `lib/main.dart` | Finance ERP client | riverpod, dio, secure storage, google_sign_in | none local ledger | `/api/finance/v1` | Sanctum + Google | Client of Laravel; must not recompute tax/GL |
| Hasim Cashier | `apps/hasim_cashier` | Mobile + Windows Flutter | Flutter | Dart | pub | `lib/main.dart` | Offline-first POS | riverpod, hive, drift, crypto, printer | Local SQLite/Hive + Laravel | `/api/cashier/v1`, `/api/pos/sync` | Sanctum + PIN | Strong local core; sync/conflict residual risk |
| Shared PHP domain | `app/Services`, `app/Support`, `app/Models` | Libraries inside monolith | Laravel | PHP | Composer | n/a | Business rules | Money, tenancy, uploads | shared | consumed by all APIs | n/a | Critical — do not split |
| E-invoicing kit | `app/EInvoicing`, `app/Models/EInvoicing` | Domain library | PHP | PHP | Composer | services + Finance XML/QR routes | QR, UBL/XML, stamps, certificates | phpseclib (via stack) | e_invoice_* tables | `/api/finance/v1/.../xml|qr` | finance auth | Foundation / harness — **not ZATCA-certified** |
| Workers / jobs | `app/Jobs`, `routes/console.php` | Queue + scheduler | Laravel | PHP | Composer | `queue:work`, `schedule:run` | WhatsApp, email, payments, AI usage, billing, stories | queue driver | shared | n/a | n/a | Code present; production worker/Redis not in repo |
| Tests | `tests/`, `apps/*/test/` | QA | PHPUnit + flutter_test | PHP/Dart | Composer/pub | `php artisan test` | Isolation, finance, POS, auth | phpunit ^12 | SQLite memory | hits HTTP | test APP_KEY | PHP suite large; Flutter Windows env UNKNOWN |

### Not found in this workspace

| Expected | Evidence |
| --- | --- |
| Separate finance/chat/POS backends | Only one `composer.json` at repo root |
| Second database | Single Laravel `config/database.php` pattern; Flutter local DBs are caches |
| Docker / compose | No `Dockerfile` at repo root |
| First-party CI | No `.github/workflows` under the project (only vendor/node_modules). `docs/HASEM-POST-REPAIR-AUDIT.md` claims `.github/workflows/ci.yml` — **stale vs this tree** |
| Git history | `fatal: not a git repository` (this workspace) |
| Native Electron/Tauri/WPF | Flutter `windows/` runners only |
| Telegram / TikTok channel backends | No services; inbox channel enum is WhatsApp/web/manual/Instagram/Messenger/email |

---

## 3. PRODUCT MAP (discovered)

```text
HASIM SMART / HASEM
├── Public web
│   ├── Landing (`/`)
│   ├── Downloads (`/downloads`)
│   ├── Legal drafts (`/privacy` `/terms` `/cookies` `/acceptable-use`)
│   ├── Public assistant (`POST /assistant/chat`)
│   └── Public QR menu (`/menu/{workspace:slug}`)
├── Auth surfaces
│   ├── Web session (Breeze + OTP + Google/Facebook Socialite)
│   ├── Core API `/api/auth/*`
│   ├── Mobile `/api/mobile/v1/auth/*`
│   ├── Cashier `/api/cashier/v1/auth/*`
│   ├── Finance `/api/finance/v1/auth/*`
│   └── Platform admin `/platform`
├── Web tenant (Blade)
│   ├── Workspace dashboard / subscriptions / employees
│   ├── Chat inbox + email + WhatsApp accounts
│   ├── Products / inventory / customers
│   ├── POS web (tables, kitchen, cashier invoices)
│   └── Finance web (`/workspace/finance`)
├── Flutter clients
│   ├── apps/hasim          → Chat / inbox
│   ├── apps/hasim_finance  → Finance ERP
│   └── apps/hasim_cashier  → POS / cashier
├── APIs (same Laravel)
│   ├── /api                Core resources
│   ├── /api/mobile/v1      Chat
│   ├── /api/cashier/v1     POS
│   ├── /api/finance/v1     Finance
│   └── /api/pos/sync       POS sync aliases
├── Shared services (single source of truth)
│   ├── Workspace + Spatie teams
│   ├── Customer / Product / Order
│   ├── Invoice / Tax / Ledger
│   ├── Payments / Subscriptions
│   └── WhatsApp / Email / AI
└── Infrastructure (intended, not fully packaged)
    ├── Queue + scheduler
    ├── Private/public storage
    └── Redis recommended, database default locally
```

---

## 4. Relationship between products

| Question | Answer from code |
| --- | --- |
| How many applications? | **4 UIs**: Laravel web (+ platform + landing), Hasim Chat, Hasim Finance, Hasim Cashier |
| How many backends? | **1** Laravel app |
| How many databases (canonical)? | **1** shared tenant DB. Flutter apps add **local caches** (Drift/Hive/SQLite) |
| Who owns customers? | Laravel `customers` table (`App\Models\Customer`) |
| Who owns invoices? | Laravel `finance_invoices` via `InvoiceService` |
| Who owns POS sales? | Laravel `orders` / `pos_cashier_invoices` via `PosOrderService`; cashier also has **local** sales before sync |
| How do they sync? | Shared DB for online. Cashier: `/sync/push` `/sync/pull` + `pos_sync_*`. Chat: outbox + Drift reconciler |

---

## 5. Status snapshot

| Surface | Works as product? | Notes |
| --- | --- | --- |
| Laravel tenancy + finance core | Yes (with residual isolation footguns) | Strong service layer + tests |
| Blade finance / inbox / POS | Yes (UI) | Dual finance UIs (Blade + Flutter) |
| Finance Flutter | Client complete enough to call API | Dark/light tokens exist; no local GL |
| Cashier Flutter | Offline core documented as strong | Local pricing + backup; sync is the risk |
| Chat Flutter | Inbox/email/stories present | Instagram/Messenger flags, not full Graph APIs |
| SaaS billing | Partial | `LocalSubscriptionBillingProvider` is the live path per `docs/product.md` |
| ZATCA / official e-invoice | Foundation only | Do not claim compliant |
| Production packaging | Weak | No Docker, no first-party CI in tree, no automated Laravel backup |

UNKNOWN — Requires runtime verification: which env this machine actually talks to; whether queue workers run; whether WhatsApp tokens are live.



---


# ARCHITECTURE-AUDIT

Audit date: 2026-09-13  
Evidence only. No code changes.

---

## 1. Style

**Modular Laravel monolith.** Presentation (Blade + three Flutter clients) → HTTP controllers / Form Requests → `app/Services/{Domain}` → Eloquent (`WorkspaceScopedModel`) → one database.

Not a microservice mesh. Not a multi-database SaaS.

---

## 2. Real request paths

### Web (session)

```text
Browser
  → routes/web.php | auth.php | platform.php
  → middleware: web, CSRF, auth, workspace.selected, workspace.member, workspace.feature
  → Workspace\*Controller + InteractsWithWorkspace
  → Domain service
  → WorkspaceScopedModel / DB
  → Blade
```

### Mobile / Cashier / Finance Flutter

```text
Flutter (Sanctum bearer + X-Workspace-Id)
  → routes/api.php groups
  → auth:sanctum + workspace.resolve + workspace.member [+ mobile.idempotency]
  → Api\{Mobile|Cashier|Finance}\V1\*
  → same domain services as web
  → DB
```

### Webhooks

```text
Provider → /whatsapp-webhook | /webhooks/resend | /api/webhooks/payments/{provider} | /api/webhooks/subscriptions/{provider}
  → CSRF excepted for WhatsApp + Resend (`bootstrap/app.php`)
  → signature verification expected in controllers/services
  → Job (ProcessIncomingWhatsAppMessage, ProcessPaymentWebhook, …)
```

### Public unauthenticated

```text
POST /assistant/chat → AssistantController → WebsiteAssistantService (rate-limited)
GET/POST /menu/{slug} → guest QR ordering
GET /whatsapp-webhook (verify)
```

---

## 3. Layer map

| Layer | Location | Notes |
| --- | --- | --- |
| Frontend web | `resources/views` (317 Blade files) | Alpine + Tailwind/Vite (`package.json`) |
| Frontend Flutter | `apps/hasim` (84 dart), `apps/hasim_finance` (49), `apps/hasim_cashier` (116) | Riverpod + go_router + dio |
| Backend | `app/` | Single process |
| APIs | `routes/api.php`, `mobile.php`, `cashier.php`, `finance-api.php` | Four public API families + webhooks |
| Database | Laravel migrations (110 files) | Additive-only policy |
| Storage | `public` + `private` disks | Merchant docs private (`docs/security.md`) |
| AuthN | Session, Sanctum, Socialite, OTP | See AUTH-AUDIT |
| AuthZ | Spatie teams + `WorkspaceAccess` + policies + `authorizeFinance*` | Elevation: owner/admin/manager |
| Queues | `app/Jobs` (13) | Default driver: database locally (`docs/PRODUCTION.md`) |
| Events | `app/Events` (10) | POS tables/orders + realtime messages |
| Listeners | `app/Listeners` (2) | Order/payment notifications only |
| Notifications | `DomainNotificationService`, push job | Partial |
| Caching | Laravel cache store | Database default; Redis recommended |
| External | Meta Graph, Resend, Stripe/HyperPay/local, OpenAI/Gemini, Google/Facebook OAuth | Token-gated |

---

## 4. Tenancy architecture

Canonical tenant: `workspaces`.

Isolation stack:

1. `workspace_id` columns
2. `WorkspaceScopedModel` global scope
3. `ResolveWorkspaceContext` + `EnsureWorkspaceMembership`
4. Spatie `PermissionRegistrar::setPermissionsTeamId`
5. Explicit `withoutGlobalScopes()->where('workspace_id', …)` in sensitive services (e.g. `InvoiceService`)

**Architectural footgun:** if `WorkspaceContext` has no id, the global scope **does not filter** (`app/Models/WorkspaceScopedModel.php` lines 76–81). Write-create without context is allowed if `workspace_id` is already on the model. Jobs/console/tests that forget to set context can leak or write across tenants.

---

## 5. Domain ownership (inside one product)

```text
Identity / Tenancy / RBAC
CRM (Customer, tags, notes, leads)
Inbox (Conversation, Message, Email, WhatsApp)
Commerce catalog (Product, Category, Inventory)
POS (Order, DiningTable, PosCashierInvoice, sync)
Finance (Invoice, Quote, Payment, Expense, Journal, Treasury, Payroll)
SaaS billing (Plan, Subscription, checkout)
Merchant KYC (MerchantProfile, documents)
AI (AiSetting, AiLog, copilot, public assistant)
E-invoice foundation (EGS, stamps, snapshots, XML/QR)
```

Business rules live in `app/Services/{Finance,Pos,Payment,WhatsApp,Subscription,AI,…}` — not in Flutter.

---

## 6. What is good

- One backend, one ledger, one customer table — correct SaaS shape for this product.
- Flutter finance is a presenter, not a second ERP.
- Route files are split by surface (`mobile`, `cashier`, `finance-api`).
- Money helper + posted-journal immutability are explicit architecture rules.
- Feature entitlements (`FeatureAccessService`) exist.

## 7. What is dangerous / inconsistent

| Issue | Evidence |
| --- | --- |
| Dual POS tax/pricing | `TaxCalculationService` vs `PosTaxCalculator` vs cashier `pricing_service.dart` |
| Dual product catalogs | `products` vs `pos_menu_items` (linked by `product_id` in later migrations, not always one row) |
| Dual finance UIs | Blade `/workspace/finance` and Flutter finance — drift risk |
| Offline POS as second ledger | `apps/hasim_cashier` local SQLite + `local_finance_repository.dart` |
| God services | `InvoiceService`, `PosOrderService` are very large |
| No repository layer in PHP | 0 files under `app/Repositories` |
| Missing CI/Docker in tree | Architecture docs describe Linux/Nginx; packaging is not in repo |
| Observability thin | Queue fail logs mentioned; no Sentry/metrics package in `composer.json` |

## 8. Intended production topology (docs, not verified running)

```text
Internet → Nginx/TLS → PHP-FPM (Laravel)
                       → queue workers
                       → cron schedule:run
         → MySQL
         → Redis (recommended, not default)
         → object/private disk
```

UNKNOWN — Requires runtime verification: actual host, SSL, workers, Redis, and whether `APP_DEBUG` is false in any live env.



---


# PRODUCT-AUDIT

Audit date: 2026-09-13

---

## 1. What is HASIM SMART?

From code and first-party docs (not marketing guesswork):

**HASIM SMART (HASEM / حاسم)** is an Arabic-first **multi-tenant SaaS for small and mid businesses** that combines:

1. **Shared inbox** (WhatsApp + email + manual) and light CRM  
2. **Finance / invoicing / double-entry lite** (quotes, invoices, expenses, payroll, ledger reports)  
3. **POS / cashier** (tables, kitchen, offline Flutter)  
4. **Platform billing** (Starter/Pro/Business/Enterprise) and **merchant KYC** to collect customer money  

Target user: Saudi/regional business owner, accountant, cashier, inbox agent — one **workspace** (individual / company / store).

SaaS = Laravel web + subscriptions + entitlements.  
Desktop = Flutter Windows builds of the three apps.  
Mobile = same three Flutter clients.  
Web = Blade tenant app + landing.  
Core platform = the Laravel monolith and shared DB.

---

## 2. Feature inventory (path completeness)

Status: Complete / Partial / Broken / Missing / Unknown  
Complete requires UI → logic → API/DB → response.

| ID | Feature | Module | FE | BE | DB | API | Auth | Perm | Valid | Tests | Status | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| F01 | Signup / workspace | Identity | Blade | yes | users/workspaces | `/api/auth/register` | guest | n/a | yes | RegistrationTest | Complete | Legal type not required |
| F02 | Login session | Identity | Blade | yes | sessions | many | yes | n/a | yes | AuthenticationTest | Complete | |
| F03 | OTP | Identity | Blade | yes | cache | `/api/auth/otp` | guest | n/a | yes | OtpProtectionTest | Complete | |
| F04 | Google/Facebook | Identity | yes | Socialite | auth_identities | social token | yes | n/a | yes | partial | Partial | Facebook live UNKNOWN |
| F05 | Plans / checkout | Billing | Blade | yes | subscriptions | `/api/subscriptions` | member | yes | yes | Subscription* | Partial | Local provider |
| F06 | Merchant KYC | Payments | Blade + platform | yes | merchant_* | n/a | yes | platform | yes | MerchantVerificationTest | Complete | needed for GMV |
| F07 | Customers | CRM | all UIs | yes | customers | web/mobile/finance/cashier | yes | mixed | yes | isolation tests | Complete | duplicate create risk |
| F08 | Leads | CRM | finance | yes | crm_leads | finance API | finance | yes | yes | PlatformCore | Complete | no full pipeline |
| F09 | Inbox WhatsApp | Chat | Blade+Flutter | yes | conversations | mobile | yes | member | yes | WhatsApp* | Partial | tokens |
| F10 | Inbox email | Chat | Blade+Flutter | yes | email_* | mobile | yes | member | yes | Email tests (some skip) | Partial | |
| F11 | Instagram/Messenger | Chat | flags | no | settings | no | n/a | n/a | no | UI only | Missing | misleading |
| F12 | Telegram/TikTok | Chat | no | no | no | no | n/a | n/a | no | no | Missing | |
| F13 | Stories | Chat | Flutter | yes | stories | mobile | yes | yes | yes | MobileStoriesTest | Complete | |
| F14 | Products / inventory | Catalog | Blade | yes | products | `/api/products` | yes | WorkspaceAccess | yes | InventoryAuthorization | Complete | |
| F15 | POS sale | POS | Blade+Flutter | yes | orders | cashier | yes | POS policies | yes | PosModule* | Partial | offline races |
| F16 | QR guest menu | POS | public | yes | orders | web | no | slug | yes | PosTableQr* | Partial | abuse |
| F17 | Shifts / drawer | POS | Flutter | no ledger | local | no | PIN | local | Flutter tests | Partial | not in GL |
| F18 | Quotes | Finance | Blade+Flutter | yes | finance_quotes | finance-api | finance | map | yes | UNKNOWN full | Partial | spec text stale |
| F19 | Invoices / payments | Finance | yes | InvoiceService | finance_invoices | finance-api | finance | yes | yes | FinancialCore | Complete | KPI drift |
| F20 | Credit notes | Finance | yes | yes | finance_credit_notes | finance-api | yes | yes | yes | present | Complete | |
| F21 | Expenses | Finance | yes | ExpenseService | finance_expenses | finance-api | yes | yes | yes | ExpenseAccounting | Complete | |
| F22 | Journals / TB / P&L | Finance | reports | AccountingService | journals | web+API reports | elevated | yes | yes | PlatformCore | Complete | close quality |
| F23 | Bank rec | Finance | treasury | BankReconciliation | statements | web | yes | yes | yes | IDOR tests | Partial | no import |
| F24 | Payroll | Finance | Blade+API | yes | payroll_* | finance-api | yes | yes | yes | FinancialCore | Partial | |
| F25 | Copilot | Finance | yes | keyword | n/a | POST | finance.view | yes | yes | isolation | Partial | not LLM |
| F26 | E-invoice ZATCA | Finance | checklist UI | harness | e_invoice_* | xml/qr | yes | yes | yes | Phase* harness | Partial | not live |
| F27 | Public assistant | AI | widget | WebsiteAssistant | ai_logs | `/assistant/chat` | no | n/a | 800 chars | AssistantChatTest | Partial | accepted risk |
| F28 | API keys | Platform | Blade | yes | api_keys hash | n/a | owner/admin | yes | yes | ApiKey* | Partial | consume path foundation |
| F29 | Website builder | Web | retired | retired | dropped | n/a | n/a | n/a | n/a | old tests may linger | Missing | do not rebuild casually |
| F30 | Appointments | — | retired | retired | dropped | n/a | n/a | n/a | n/a | old failures in 09-02 audit | Missing | |

---

## 3. Core business flows (summary)

| Flow | Entry | Failure points |
| --- | --- | --- |
| Signup | `/register` | email deliverability UNKNOWN |
| Login | `/login`, APIs | throttle, Socialite state |
| Workspace | created at register / choose | membership enum mapping |
| Customer | web/API/cashier | duplicates |
| Conversation | webhook or manual | WhatsApp secret |
| Order | POS / QR / API | offline duplicate |
| Invoice | finance web/API | tax engine vs POS tax |
| Payment | finance + payment links + POS | KYC, provider, race |
| Refund | credit note / POS return / payment reverse | elevation |
| Expense | finance | reversal path |
| Report | ledger reports | KPI ≠ P&L |
| POS sale | cashier | sync |
| Employee | invite | persistable role |
| Permission | Spatie + elevation | manager = all finance |
| Subscription | checkout | local provider = no real charge |

---

## 4. Feature gaps (from this product’s own flows, not competitors)

- Real subscription charging (Stripe/HyperPay live)
- Live merchant settlement
- Official e-invoicing clearance
- Instagram/Messenger/Telegram actually connected
- Unified product catalog
- Server-side shifts / cash-up in GL
- Quote→order (sales order) as first-class if quotes convert only to invoices
- Credit limit hard block
- Warehouses / FIFO / serials
- Export completeness (CSV/PDF everywhere)
- EN locale parity
- Cookie consent + complete legal
- Support / ticketing
- Account close / data export
- 2FA for owners and platform admin
- Multi-currency posting

---

## 5. Cross-app integration

| Entity | Source of truth | Chat | Finance | POS | Sync risk |
| --- | --- | --- | --- | --- | --- |
| Workspace | Laravel | yes | yes | yes | header X-Workspace-Id |
| User | Laravel users | yes | yes | yes + PIN | |
| Customer | `customers` | yes | yes | can create | **duplicates** |
| Product | `products` | AI catalog | yes | menu items | **dual catalog** |
| Invoice | `finance_invoices` | no | yes | cashier invoices / orders | **three documents** |
| Order | `orders` | no | POS invoice API | yes | offline |
| Payment | `payments` + invoice payments | no | yes | POS | double pay |
| Employee | membership + finance employees | invite | payroll profiles | cashier users | two employee concepts |

Shared DB is the online sync. Events/queues for WhatsApp/email/payments. Cashier + chat use local DBs.

**Duplicate data risk:** customer created in cashier and finance; menu item vs product; POS order vs finance invoice vs cashier invoice (`pos_cashier_invoices` + `pos_cashier_invoice_id` on orders).



---


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



---


# AUTH-AUDIT

Audit date: 2026-09-13  
No authentication bypass was executed live.

---

## 1. Surfaces

| Surface | Files | Mechanism |
| --- | --- | --- |
| Web login/register/reset/verify | `routes/auth.php`, `app/Http/Controllers/Auth/*` | Session, Breeze |
| Phone OTP | `routes/web.php` `/otp/*`, `PhoneOtpController` | Cache-hashed OTP |
| Social web | `SocialLoginController` google/facebook | Socialite |
| Core API | `routes/api.php` `/api/auth/*` | Sanctum |
| Mobile | `routes/mobile.php` | `MobileAuthService` |
| Cashier | `routes/cashier.php` | same family + PIN locally |
| Finance Flutter | `routes/api.php` finance auth group | Sanctum + Google start/status |
| Platform | `routes/platform.php` | `platform_admin` guard |

---

## 2. Control checklist

| Control | Status | Evidence |
| --- | --- | --- |
| Login | Implemented | Web + 4 API families |
| Signup | Implemented | `RegisteredUserController`, `POST /api/auth/register` throttle 10/min |
| Logout | Implemented | session destroy + Sanctum token revoke endpoints |
| Password reset | Implemented | web + mobile/cashier/finance forgot/reset, throttled |
| Email verification | Implemented (MustVerifyEmail on User) | `routes/auth.php` signed + throttle 6/min |
| Phone verification / OTP | Implemented | hashed, 5 min, attempt cap (docs + tests) |
| Google | Implemented | Socialite + token exchange `/api/auth/social/token` |
| Facebook | Implemented (web/API provider list) | `SocialAuthService` allows google\|facebook |
| Sessions | Laravel session | production checklist encrypt + secure cookie |
| Tokens | Sanctum personal access | per-token TTL 30/60 days |
| Refresh tokens | **Missing** as a first-class rotation protocol | logout + re-login |
| Cookies | Framework defaults | UNKNOWN live flags |
| Remember me | Breeze default | UNKNOWN if enabled in view |
| Multi-device | Mobile sessions list/delete | `DeviceSessionController` |
| Expiration | Per-token, global null | `config/sanctum.php` |
| Account recovery | Email reset + OTP | no government ID recovery |
| Brute force | Throttles on login/OTP | `throttle:10,1`, `mobile-login`, named OTP limiters |
| Rate limiting | Present on auth routes | not proven on every web POST |
| CSRF | Web yes | API token auth |
| Password hashing | Laravel hasher | User model |
| Password policy | Framework / Breeze | UNKNOWN complexity beyond Laravel defaults |

---

## 3. Findings

### AUD-AUTH-001
- Category: Authentication
- Severity: High
- Title: Long-lived Sanctum tokens without refresh rotation
- Location: `config/sanctum.php`, `config/security.php` (referenced in PRODUCTION.md)
- Evidence: expiration null globally; mobile/cashier 60 days
- Impact: Stolen device token = 60 days of tenant API
- Exploitability: Medium
- Recommendation: Shorter access TTL + refresh; revoke all on password reset
- Effort: M
- Priority: P1

### AUD-AUTH-002
- Category: Authentication
- Severity: Medium
- Title: Four parallel auth implementations can drift
- Location: `Api\AuthController`, `Api\Mobile\V1\AuthController`, `Api\Cashier\V1\AuthController`, `Api\Finance\V1\AuthController`
- Evidence: duplicated login/forgot/social
- Impact: A patch on one family may not apply to others
- Exploitability: Low
- Recommendation: Keep `MobileAuthService` / `AuthenticationService` as only writers
- Effort: M
- Priority: P2

### AUD-AUTH-003
- Category: Authentication
- Severity: Medium
- Title: Accountant / receptionist roles collapse on persist
- Location: `WorkspaceAccess::persistableMembershipRole`
- Evidence: receptionist → agent; default → member. RANK has accountant=40 but MEMBERSHIP_ROLES enum excludes accountant
- Impact: Product language ≠ DB role; users think they invited an accountant, get member
- Exploitability: Low (integrity / UX) / Medium if UI shows accountant permissions they do not have
- Recommendation: Dedicated migration only if product needs the enum
- Effort: M
- Priority: P2

### AUD-AUTH-004
- Category: Authentication
- Severity: Low
- Title: Default platform admin password in example env
- Location: `.env.example` `PLATFORM_ADMIN_PASSWORD=password`
- Impact: If copied to production, full platform takeover
- Recommendation: Force change on first login; generate random in setup
- Effort: S
- Priority: P1

---

## 4. Historical fixes (re-verified as present in code/docs)

Prior repair (`docs/HASEM-POST-REPAIR-AUDIT.md`, 2026-09-02) claimed OTP enumeration, role escalation, API-key authz, inventory authz fixed. Current code still contains `WorkspaceAccess` hierarchy and OTP docs. **Runtime re-test was not executed in this audit.**

UNKNOWN — Requires runtime verification: login lockout after N failures beyond throttle, cookie `Secure`/`HttpOnly`/`SameSite` on a live host, Facebook app review, Google token audience validation.



---


# MULTI-TENANCY-AUDIT

Audit date: 2026-09-13  
Priority: maximum. Isolation is `workspace_id` + `WorkspaceScopedModel` + membership middleware + Spatie teams.

---

## 1. Design (good)

| Control | File |
| --- | --- |
| Tenant table | `workspaces` — `App\Models\Workspace` |
| Membership | `workspace_users` — `WorkspaceUser` (**not** globally scoped) |
| Context | `App\Support\Tenancy\WorkspaceContext` |
| Resolve | `ResolveWorkspaceContext` + `WorkspaceResolverService` |
| Membership gate | `EnsureWorkspaceMembership` |
| Feature gate | `EnsureFeatureAccess` |
| Model scope | `WorkspaceScopedModel` |
| Route binding | scoped `resolveRouteBinding` |
| Cross-write guard | creating/updating throws `Cross-workspace write attempt` when context **is** set |
| Tests | `tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php` plus many finance IDOR 404s |

Cross-workspace access is specified to **404**, not 403 (`AGENTS.md`).

---

## 2. Tenant Isolation Findings

### Tenant Isolation Finding — AUD-TEN-001
- Severity: Critical
- Location: `app/Models/WorkspaceScopedModel.php` global scope
- Evidence: No `workspace_id` predicate when context is null. Jobs (`SendWhatsAppMessage`, `ProcessAIResponse`, `SyncEmailInboxJob`, billing commands) must set context themselves.
- Attack Scenario: A scheduled command or webhook handler loads `Customer::find($id)` or `FinanceInvoice::query()->…` without binding workspace → returns any tenant’s row.
- Impact: Cross-tenant PII, invoices, chats
- Recommendation: Fail closed for HTTP; require explicit `forWorkspace($id)` on console; add lint/test that jobs set context
- Effort: M
- Priority: P0

### Tenant Isolation Finding — AUD-TEN-002
- Severity: High
- Location: `app/Models/EmailLog.php`, `EmailAttachment.php`, `EmailCampaignRecipient.php`, `EmailWebhookEvent.php`
- Evidence: These extend `Model`, not `WorkspaceScopedModel`. `EmailLog` is fillable including `workspace_id`.
- Attack Scenario: A controller that lists `EmailLog::query()` without a workspace constraint can dump recipients across tenants.
- Impact: Email PII leakage
- Recommendation: Scope or always constrain in one service; add IDOR tests
- Effort: S
- Priority: P0

### Tenant Isolation Finding — AUD-TEN-003
- Severity: High
- Location: `DevicePushToken`, `StoryView`, `ApiIdempotencyKey`, `NotificationPreference`, `AuthIdentity`
- Evidence: Unscoped models that can hold user/device/tenant-adjacent data
- Attack Scenario: Push fan-out or idempotency lookup by raw key without workspace
- Impact: Cross-device notification or replay across tenants if keys collide poorly
- Recommendation: Add workspace_id + scope where the row is tenant-owned
- Effort: M
- Priority: P1

### Tenant Isolation Finding — AUD-TEN-004
- Severity: Medium
- Location: `InvoiceService` uses `Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)`
- Evidence: Correct pattern — but proves developers routinely disable the scope. A missed `where workspace_id` after `withoutGlobalScopes()` is an IDOR.
- Attack Scenario: Developer copies the withoutGlobalScopes call and forgets the where
- Impact: Cross-tenant customer attach on invoice
- Recommendation: Helper `Customer::inWorkspace($id)` that cannot omit the constraint
- Effort: S
- Priority: P1

### Tenant Isolation Finding — AUD-TEN-005
- Severity: Medium
- Location: Public menu `Route::get('/menu/{workspace:slug}')`
- Evidence: Resolves workspace by public slug, not membership
- Attack Scenario: Enumeration of slugs → customer PII on menu / order injection
- Impact: Tenant data exposure at the marketing/QR edge
- Recommendation: Only published POS workspaces; generic 404
- Effort: S
- Priority: P2

### Tenant Isolation Finding — AUD-TEN-006
- Severity: Low (control)
- Location: Platform admin update skip in `WorkspaceScopedModel::updating`
- Evidence: `if (auth('platform_admin')->check()) return;`
- Attack Scenario: Compromised platform admin = all tenants (expected). Session fixation on that guard is catastrophic.
- Impact: Platform-wide breach
- Recommendation: Hardware 2FA, IP allowlist, no default password
- Effort: M
- Priority: P1

---

## 3. What looks solid

- Finance invoice create validates customer/supplier belong to the same workspace.
- Finance API and cashier API apply `workspace.resolve` + `workspace.member`.
- Mobile attachment download uses **signed** URL (`routes/mobile.php`).
- Copilot/search tests claimed grounded isolation (`docs/HASEM_IMPLEMENTATION_STATUS.md`).
- Public assistant is documented as not accepting arbitrary `workspace_id` (`docs/security.md`) — **re-verify `WebsiteAssistantService` at runtime**.

UNKNOWN — Requires runtime verification: every artisan command path, every job `handle()`, and every `DB::table()` query.



---


# DATABASE-AUDIT

Audit date: 2026-09-13  
Source: `database/migrations/*.php` (110 files), models, docs. No live DB was queried.

---

## 1. Inventory

| Item | Count / note |
| --- | --- |
| Migrations | 110 PHP files |
| Eloquent models (approx) | ~120 including Finance/EInvoicing/CRM/Contract |
| Workspace-scoped models | Vast majority of tenant tables |
| Repositories | 0 |
| Duplicate timestamps | `2026_09_01_120000_create_pos_customer_sessions_table.php` and `2026_09_01_120000_hasim_mobile_api_foundation.php` (`docs/PRODUCTION.md`) |
| Retired domains | Website builder + appointment tables: `2026_09_12_180000_retire_website_builder_and_appointment_tables.php` |

Exact live table list: UNKNOWN — Requires `php artisan db:show` or MySQL `SHOW TABLES`.

---

## 2. Core table families (from models + migrations)

| Family | Tables (representative) | Tenant key |
| --- | --- | --- |
| Identity | `users`, `auth_identities`, `platform_admins` | user is global |
| Tenancy | `workspaces`, `workspace_users`, `workspace_feature_flags`, `workspace_addons`, `workspace_usage_meters` | workspace |
| Plans | `plans`, `plan_addons`, `subscriptions`, checkout/billing attempts | mix |
| CRM | `customers`, tags, notes, groups, `crm_leads` | workspace |
| Inbox | `conversations`, `messages`, `message_attachments` | workspace |
| WhatsApp | accounts, phone numbers, templates, outbound | workspace |
| Email | accounts, messages, campaigns, contacts, **logs/attachments unscoped** | mixed |
| Catalog | `categories`, `products`, `product_variants`, `inventory_movements` | workspace |
| POS | `orders`, `order_items`, dining tables, sessions, menu items, cashier invoices, returns, devices, sync | workspace |
| Finance | invoices/items/payments/attachments, quotes, receipts, credit notes, expenses, accounts, journals, treasury, payroll, PO, projects, tax rates, settings | workspace |
| E-invoice | egs_units, documents, certificates, stamps, security records, issued snapshots | workspace |
| Merchant | profiles, documents, document types (types global) | mixed |
| AI | `ai_settings`, `ai_logs` | workspace |
| Audit | `audit_logs` (workspace nullable for system) | mixed |
| API | `api_keys`, `api_idempotency_keys` | mixed |

---

## 3. Integrity observations (schema-level)

### Good

- Billing occurrence unique `(billing_schedule_id, billing_occurrence_key)` — `2026_09_02_210000_*`
- Treasury transfer unique `(workspace_id, reference)` — spec
- Money columns typically `decimal(14,2)`; invoice qty `decimal(12,3)`
- Soft deletes on several finance/CRM entities
- Additive migrations only (project rule)

### Problems / risks

| Issue | Evidence | Risk |
| --- | --- | --- |
| Dual invoice status columns | `invoice_status` + `payment_status` + legacy `status` (`InvoiceStateService`, docs) | Stale/conflicting status if any writer skips sync |
| Float in ops commands | `routes/console.php` `finance:invoices:integrity-report` casts SQL `sum` to `(float)` | Misleading integrity, not a writer — still a smell |
| Integer stock vs decimal qty | Implementation status honesty | Rounding at inventory boundary |
| Dangerous cascades | UNKNOWN without reading every FK `onDelete` | Could wipe tenant finance on workspace delete |
| Missing indexes | Partial indexes added for journal lines/products | Report N+1 / full scans under load |
| Orphan records | Cannot prove without live DB | UNKNOWN |
| Duplicate customers | `client_reference` added `2026_09_02_000100_*`; POS can create customers independently | Duplicate CRM rows |
| Enum narrower than product | `workspace_users.membership_role` | Role mapping |

---

## 4. Findings

### AUD-DB-001
- Category: Database
- Severity: Medium
- Title: Duplicate migration timestamps
- Location: `database/migrations/2026_09_01_120000_*` (two files)
- Evidence: Documented; Laravel records filename so both can run; rename is a history rewrite
- Impact: Operator confusion; broken deploys if someone “fixes” names
- Recommendation: Do not rename on existing envs; document in runbooks
- Effort: S
- Priority: P2

### AUD-DB-002
- Category: Database
- Severity: High
- Title: No proven backup/restore for the Laravel database in this repo
- Location: `docs/deployment.md` mentions file backups; architecture doc wishes daily backups; no backup job/script in first-party tree
- Impact: Total data loss
- Recommendation: Automated MySQL dump + restore drill
- Effort: M
- Priority: P0

### AUD-DB-003
- Category: Database
- Severity: Medium
- Title: Legacy `finance_invoices.status` retained
- Location: finance invoice domain docs + `finance:invoices:status-audit`
- Impact: Writers using legacy column can desync payment/invoice state
- Recommendation: Keep until compatibility window; block new writes to legacy-only
- Effort: M
- Priority: P2

UNKNOWN — Requires runtime verification: FK cascade map, unused tables after website-builder retirement, actual index usage, orphan payments.



---


# FINANCE-AUDIT

Audit date: 2026-09-13  
This is the most important money domain. No journals were posted in this audit.

---

## 1. What the system actually is

**Hybrid: invoicing + a real double-entry core**, not a full IFRS accounting suite.

| Capability | Status | Evidence |
| --- | --- | --- |
| Chart of accounts | Implemented | `ChartOfAccountsService`, `FinanceAccount` |
| Journals (draft → posted, reverse) | Implemented | `AccountingService`, immutable posted rows |
| Period / year close blocks posting | Implemented | `FinancialPeriodGuardService` |
| Trial balance / P&L / BS / GL | Implemented (reports) | `LedgerReportService` |
| Cash flow | Partial | Movement of accounts 1000/1100 — not IAS 7 statement |
| Bank reconciliation | Partial | Match only; no auto JE; no CSV/OFX |
| Multi-currency FX revaluation | Missing | Accounts 4200/5400 reserved |
| Invoices + items + tax + payments + reverse | Implemented | `InvoiceService`, `TaxCalculationService`, `Money` |
| Quotes | Implemented | models + `/api/finance/v1/quotes` (older spec text is stale) |
| Credit notes | Implemented | `FinanceCreditNote*` |
| Receipts + PDF/email | Implemented | `ReceiptService` |
| Expenses + reversal on delete | Implemented | `ExpenseService` (repair audit) |
| Payroll / advances | Implemented | finance employee tables |
| Purchase orders → bill | Implemented | `PurchaseOrderService` |
| Recurring billing schedules | Implemented | unique occurrence key |
| Projects profitability | Partial | invoice − expense sums |
| Dashboard KPIs | Partial / divergent | Cards may ≠ ledger P&L (`docs/HASEM_IMPLEMENTATION_STATUS.md`) |
| Official e-invoicing (ZATCA) | Foundation only | XML/QR/stamp harness — **not certified** |

---

## 2. Money math

- Canonical helper: `App\Support\Money\Money` (integer minor units).
- Invoice/tax/treasury/accounting services are specified to use it.
- **Leak:** `Money::round()` returns `float`. POS and reports call it often (`PosTaxCalculator`, `PosOrderService` sums).
- DB columns remain `decimal`. PHP float must not become the writer of record.

POS has a **second** tax engine: `PosTaxCalculator` — exclusive workspace rate, and **client-supplied line tax is kept when present** (`attachLineTax`).

Cashier Flutter has `lib/core/pos/domain/pricing_service.dart` and `local_finance_repository.dart` — local totals for offline. This can diverge from Laravel `InvoiceService` if sync posts client numbers.

---

## 3. Integrity risks

### AUD-FIN-001
- Category: Finance
- Severity: Critical
- Title: Dashboard / KPI formulas can disagree with ledger P&L
- Location: `PeriodComparisonService`, finance dashboard views, `docs/HASEM_IMPLEMENTATION_STATUS.md` honesty #1
- Evidence: Spec: “Dashboard metric cards are still a simplified formula; use ledger P&L for accounting close.”
- Impact: Owner makes cash/tax decisions on the wrong number
- Exploitability: n/a (integrity)
- Recommendation: Dashboard cards read ledger aggregates only
- Effort: M
- Priority: P0

### AUD-FIN-002
- Category: Finance
- Severity: Critical
- Title: POS / offline clients can supply tax amounts
- Location: `PosTaxCalculator::attachLineTax`, `PosOrderService` snapshot `tax_amount`
- Evidence: “Client-supplied line tax is kept when present.”
- Impact: Under/over tax, wrong VAT payable, ZATCA mismatch later
- Recommendation: Server always recomputes; treat client tax as hint only
- Effort: M
- Priority: P0

### AUD-FIN-003
- Category: Finance
- Severity: High
- Title: Two tax engines
- Location: `app/Services/Finance/Tax/*` vs `app/Services/Pos/PosTaxCalculator.php`
- Impact: Same cart totals differently on finance vs POS
- Recommendation: One `TaxCalculationService` for all issued documents
- Effort: L
- Priority: P1

### AUD-FIN-004
- Category: Finance
- Severity: High
- Title: Elevated managers can perform finance mutations
- Location: `AuthorizesFinanceApi::financePermissionMap` ORs `$elevated` into every key
- Impact: Privilege = financial loss (issue/cancel/reverse)
- Recommendation: Elevation for view + issue maybe; not delete/reverse/settings
- Effort: M
- Priority: P0

### AUD-FIN-005
- Category: Finance
- Severity: High
- Title: Concurrent payments / stock races not proven on MySQL
- Location: invoice TX + `TreasuryBalanceService::lockForUpdate`; docs: SQLite tests ≠ InnoDB
- Impact: Double payment, negative stock, wrong AR
- Recommendation: MySQL isolation tests; unique payment idempotency keys
- Effort: L
- Priority: P1

### AUD-FIN-006
- Category: Finance
- Severity: Medium
- Title: Integrity artisan uses float sums
- Location: `routes/console.php` `finance:invoices:integrity-report`
- Impact: False confidence in reconciliation
- Recommendation: Integer minor-unit sums
- Effort: S
- Priority: P2

### AUD-FIN-007
- Category: Finance
- Severity: Medium
- Title: Copilot is not an accountant
- Location: `FinanceCopilotService` (keyword/SQL aggregates)
- Impact: Users trust a projection labeled as insight
- Recommendation: Keep read-only; never auto-post (already a platform rule)
- Effort: S
- Priority: P2

---

## 4. TAX / ZATCA readiness (do not claim compliant)

| Item | Classification | Evidence |
| --- | --- | --- |
| VAT rates / tax number fields | Partial | Tax engine + settings + legal placeholder `[الرقم الضريبي]` |
| Invoice numbering | Implemented | workspace-unique patterns in invoice domain |
| QR | Partial (foundation) | `app/EInvoicing/QR`, Phase8 tests |
| XML / UBL mapping | Partial | `app/EInvoicing/Xml`, mapping exceptions |
| UUID / snapshots | Partial | `IssuedDocumentSnapshot`, e_invoice documents |
| Cryptographic stamp | Partial / harness | Phase9 tests; production profile throws `ProductionCryptographicProfileException` |
| Credit / debit notes | Partial | Credit notes exist; debit notes not first-class in models list |
| Simplified vs standard invoices | Partial | `InvoiceTypeCatalogTest` |
| Official clearance / reporting | Missing | Spec: “Do not invent ZATCA claims” |
| JoFotara / AE | Missing | Country profiles only |

**Not compatible / not certified.** Foundation code + unit harnesses only.

---

## 5. What must not be broken

- `AccountingService` draft-then-post + `Money::cmp` balance check
- Posted journal immutability + reverse-only corrections
- Period guard
- `InvoiceService` workspace checks on customer/supplier
- Integer money in tax/invoice issue path
- Billing occurrence unique key
- Expense delete → reversal (not erase)
- Finance Flutter **must not** grow local tax/GL math

UNKNOWN — Requires runtime verification: a full sale→payment→TB→P&L on MySQL with concurrent cashiers.



---


# CHAT-AUDIT

Audit date: 2026-09-13

---

## 1. What exists

| Channel | Backend | UI flag | Live send/receive |
| --- | --- | --- | --- |
| WhatsApp Cloud | `WhatsAppService`, `WhatsAppOutboundService`, webhook, `SendWhatsAppMessage`, `ProcessIncomingWhatsAppMessage` | Yes | Token-gated (`docs/product.md`, `docs/whatsapp.md`) |
| Email | accounts, inbox sync job, campaigns, Resend webhook | Yes | Depends on mailer / Resend secrets |
| Web / manual | `Conversation.channel` enum | Yes | In-app |
| Instagram | Channel cards `connected` from workspace settings | Yes | **No Graph Instagram service found** |
| Facebook Messenger | Same settings flags | Yes | **No Messenger ingest service found** |
| Telegram | — | No | Missing |
| TikTok | — | No | Missing |

Channel enum: `whatsapp,web,manual,instagram,facebook_messenger,email` (`StoreConversationRequest`).

Surfaces:

- Blade inbox: `resources/views/workspace/communication/inbox/*`
- Flutter: `apps/hasim` conversations, email, stories, contacts
- Mobile API: `routes/mobile.php`
- Core API: `/api/conversations`, `/api/messages`

---

## 2. Pipeline

```text
Inbound WhatsApp → WhatsAppWebhookController → ProcessIncomingWhatsAppMessage
  → Conversation/Message (workspace scoped)
Outbound → SendWhatsAppMessage job → Graph API
Email inbound → SyncEmailInboxJob / Resend webhook
Mobile send → ConversationController::storeMessage + throttle + idempotency
Local Flutter → Drift/Hive outbox (`outbox_worker.dart`, reconciler)
Realtime → events MessageCreated / ConversationUpdated (broadcast channels)
```

---

## 3. Findings

### AUD-CHAT-001
- Category: Chat
- Severity: High
- Title: Instagram / Messenger presented as channels without implementations
- Location: `ChannelController` (web + mobile), conversation filters
- Evidence: `connected` is a settings boolean; no Instagram/Telegram/TikTok services under `app/Services`
- Impact: Users believe omnichannel is live; messages silently do not route
- Recommendation: Mark “soon” or hide until webhooks exist
- Effort: S
- Priority: P1

### AUD-CHAT-002
- Category: Chat
- Severity: High
- Title: WhatsApp is not production-complete without secrets and verified webhooks
- Location: `config` WHATSAPP_*, `WhatsAppWebhookController`
- Evidence: product docs: “real send requires WHATSAPP_*”
- Impact: Lost customer messages / failed campaigns
- Recommendation: Health check “cannot send” in UI; fail closed
- Effort: M
- Priority: P1

### AUD-CHAT-003
- Category: Chat
- Severity: Medium
- Title: Duplicate / lost messages across offline outbox + server
- Location: `apps/hasim/lib/core/local_db/*`
- Evidence: local reconciler + server idempotency middleware on mobile group
- Impact: Double replies or missing history
- Recommendation: Idempotency keys end-to-end; tests for replay
- Effort: M
- Priority: P1

### AUD-CHAT-004
- Category: Chat
- Severity: Medium
- Title: Assignment / team inbox is lighter than users expect
- Location: `Conversation`, `ConversationUserState`
- Evidence: mute/archive/read exist on mobile; no full assignment service inventory
- Impact: Shared inbox collisions
- Recommendation: Explicit assignee + permissions tests
- Effort: M
- Priority: P2

### AUD-CHAT-005
- Category: Chat
- Severity: Medium
- Title: AI auto-reply can post into the same conversation
- Location: `AIService::generateReply`, `ProcessAIResponse`
- Impact: Prompt injection; leaking catalog; cost
- Recommendation: Confirm feature flag; never include other workspaces (query already filters workspace_id)
- Effort: M
- Priority: P1

---

## 4. Feature statuses

| Feature | Status |
| --- | --- |
| Conversations / messages | Complete (WhatsApp/email/manual) |
| Attachments | Partial (signed mobile download; SecureUpload should be used) |
| Search | Partial (mobile search + finance search are separate) |
| Customer profile beside chat | Partial (inbox customer-details views) |
| Notifications / push | Partial (`SendPushNotificationJob`, Flutter `push_service.dart`) |
| Stories | Implemented (expire job) |
| Email campaigns | Implemented (jobs) |
| Webhooks retry | Partial (queue failing log; UNKNOWN DLQ) |

UNKNOWN — Requires runtime verification: Meta signature, message ordering, media download, campaign bounce handling.



---


# POS-AUDIT

Audit date: 2026-09-13

---

## 1. Surfaces

| Surface | Path | API |
| --- | --- | --- |
| Blade POS | `app/Http/Controllers/Workspace/Pos/*`, `resources/views/workspace/pos/*` | session |
| Cashier Flutter | `apps/hasim_cashier` | `/api/cashier/v1` |
| Sync aliases | `routes/api.php` `/api/pos/sync` | Sanctum |
| Public QR menu | `/menu/{slug}` | guest |

Shared server logic: `PosOrderService`, `PosCartService`, `PosReturnService`, `PosSyncPushService`, `PosSyncPullService`, `PosTaxCalculator`.

---

## 2. Domain

| Area | Server | Local cashier |
| --- | --- | --- |
| Products / menu | `products` + `pos_menu_items` | catalog repo + Hive/Drift |
| Orders | `orders` / `order_items` | local + push |
| Tables | `dining_tables`, `table_sessions` | table workspace UI |
| Payments | order payments / cashier invoices | payment sheet |
| Discounts | server + client snapshots | cart |
| Refunds / returns | `PosReturnService` | `return_service.dart` |
| Receipts / print | PDF/print services | `printer_service.dart` |
| Inventory | `InventoryService` | `stock_engine.dart` |
| Users / PIN | workspace members | `pin_login_screen`, `pin_hasher` |
| Shifts | **Partial / local** | `shift_service.dart` — platform spec: not first-class ledger |
| Cash drawer | UNKNOWN / local | documented in POS readiness docs |
| Offline | sync operations tables | `sync_engine_v2.dart`, queue, conflicts |
| Backup | none on Laravel | encrypted local backup (`docs/pos-production-readiness.md`) |

---

## 3. Findings

### AUD-POS-001
- Category: POS
- Severity: Critical
- Title: Offline sale + server sale can duplicate
- Location: `PosSyncPushService`, `pos_sync_operations`, Flutter `sync_engine_v2.dart`
- Evidence: Idempotency middleware on cashier group; still a distributed system
- Impact: Double revenue, double stock decrement, customer charged twice
- Recommendation: Unique `client_reference` enforced at DB; reject replay; reconcile report
- Effort: L
- Priority: P0

### AUD-POS-002
- Category: POS
- Severity: Critical
- Title: Local pricing is a second source of truth
- Location: `apps/hasim_cashier/lib/core/pos/domain/pricing_service.dart`
- Evidence: Offline-first POS v2 docs; Flutter money math exists despite platform rule for finance Flutter
- Impact: Ticket total ≠ finance invoice
- Recommendation: Treat local totals as estimates; Laravel recomputes on accept
- Effort: L
- Priority: P0

### AUD-POS-003
- Category: POS
- Severity: High
- Title: Dual catalog (SKU product vs menu item)
- Location: `Product` vs `PosMenuItem` (`product_id` added later)
- Impact: Stock on one, sale on the other
- Recommendation: Single product id on every sold line
- Effort: L
- Priority: P1

### AUD-POS-004
- Category: POS
- Severity: High
- Title: Public menu order injection
- Location: `PosCustomerMenuController`, `routes/web.php`
- Impact: Fake kitchen tickets
- Recommendation: Flag + throttle + optional PIN
- Effort: S
- Priority: P1

### AUD-POS-005
- Category: POS
- Severity: Medium
- Title: Shifts / cash drawer not in Laravel ledger
- Location: spec “Not yet first-class”; Flutter `shift_service.dart`
- Impact: Cash variance invisible to finance P&L
- Recommendation: Post opening/closing as treasury journals
- Effort: L
- Priority: P2

### AUD-POS-006
- Category: POS
- Severity: Medium
- Title: Printer / Windows desktop packaging incomplete
- Location: `printer_service.dart`, Flutter windows runners
- Evidence: Historical POS docs said printing failed in earlier plans; current code exists
- Impact: Restaurant cannot operate
- Recommendation: Device QA matrix
- Effort: M
- Priority: P2

---

## 4. Permissions

Cashier API uses membership + feature + idempotency. Cashiers are **not** finance-elevated (`AuthorizesFinanceApi` comment). Web POS has `PosBaseController` + policies (`OrderPolicy`, `DiningTablePolicy`, `PosCashierInvoicePolicy`).

UNKNOWN — Requires runtime verification: cashier cannot hit `/api/finance/v1` invoice issue; PIN bypass; split tender; offline clock skew.



---


# AI-AUDIT

Audit date: 2026-09-13

---

## 1. Providers and entry points

| Component | File | Provider |
| --- | --- | --- |
| Manager | `app/Services/AI/AiProviderManager.php` | openai, google_ai_studio/gemini |
| OpenAI | `app/Services/AI/Providers/OpenAiProvider.php` | `config('services.openai.key')` |
| Workspace reply | `AIService` | conversation + 20 products |
| Public / website assistant | `WebsiteAssistantService`, `AssistantController` | Gemini/OpenAI + fallback |
| Finance copilot | `FinanceCopilotService` | **Keyword / SQL aggregates, not tool-calling LLM** |
| POS menu AI | `PosMenuAiService`, public `menu/.../ai-chat` | guest-capable |
| Usage | `FeatureAccessService` meters `ai_usage` / `ai_tokens`; `UpdateAIUsage` job | |

Keys: `GEMINI_API_KEY`, `OPENAI_API_KEY` in `.env.example`. Default provider google_ai_studio.

---

## 2. Can AI see another tenant?

`AIService` loads products with `where('workspace_id', $conversation->workspace_id)` and instructions “استخدم فقط بيانات نفس workspace”.  
`Workspace::withoutGlobalScopes()->find($conversation->workspace_id)` is used if scoped find fails — still by conversation’s id.

**If a conversation row were cross-tenant (see MULTI-TENANCY), AI would follow that workspace_id.** Isolation is only as strong as conversation integrity.

Public assistant: docs say no arbitrary workspace_id; stock omitted on public site. UNKNOWN — Requires reading every branch of `WebsiteAssistantService` at runtime with a crafted prompt.

Copilot: workspace SQL only; confirmation required for create-invoice preview; must not post/refund (`HASEM_MASTER_PLATFORM_SPEC.md` rule 6).

---

## 3. Findings

### AUD-AI-001
- Category: AI
- Severity: High
- Title: Prompt injection + catalog/PII in system prompt
- Location: `AIService` json_encode of products including stock/price
- Impact: Customer or attacker extracts inventory; jailbreak ignores instructions
- Recommendation: Minimize fields; separate retrieval; output filter
- Effort: M
- Priority: P1

### AUD-AI-002
- Category: AI
- Severity: High
- Title: Full prompt/response persisted
- Location: `ai_logs.input_payload` / `output_payload`
- Impact: GDPR/PII retention; prompt leak via backups
- Recommendation: Hash/redact; short TTL
- Effort: S
- Priority: P1

### AUD-AI-003
- Category: AI
- Severity: High
- Title: Unauthenticated public + QR-menu AI
- Location: `AssistantController`, `PosCustomerMenuController::askAi`
- Impact: Cost DoS; brand-damaging replies
- Recommendation: Budget + kill switch
- Effort: S
- Priority: P1

### AUD-AI-004
- Category: AI
- Severity: Medium
- Title: Users may think copilot is an LLM accountant
- Location: implementation status honesty #2
- Impact: Wrong business decisions
- Recommendation: UI label “مؤشرات من الدفاتر” not “ذكاء”
- Effort: S
- Priority: P2

### AUD-AI-005
- Category: AI
- Severity: Medium
- Title: Fallback discloses missing Gemini key
- Location: `WebsiteAssistantService` reason `missing_gemini_key`
- Impact: Config reconnaissance
- Recommendation: Generic fallback message
- Effort: S
- Priority: P2

---

## 4. Cost / rate / permissions

- Feature entitlements can block over-quota (`assertWithinLimit`).
- Public path has extra RateLimiter (8/min 40/day).
- Finance copilot requires `finance.view` via `CopilotClientController`.
- AI settings API: `/api/ai/settings` behind workspace member.

No hard dollar budget object was found (only token meters).

UNKNOWN — Requires runtime verification: whether provider keys are set; actual token spend; prompt-injection corpus.



---


# FRONTEND-AUDIT

Audit date: 2026-09-13  
Includes Blade web + notes on Flutter. Visual runtime was **not** re-walked in this audit (UNKNOWN for broken pixels).

---

## 1. Inventory

| Surface | Files | Stack |
| --- | --- | --- |
| Blade | 317 `*.blade.php` | Laravel + Alpine + Tailwind/Vite |
| Hasim Chat | 84 dart lib files | Riverpod, go_router |
| Hasim Finance | 49 dart lib files | Shared tokens `FinancePalette` |
| Hasim Cashier | 116 dart lib files | Dedicated Hasim color/spacing/radius |

No React/Vue SPA in the monolith (Vite builds CSS/JS for Blade).

---

## 2. Design system

| Token area | Blade | Finance Flutter | Cashier Flutter | Chat Flutter |
| --- | --- | --- | --- | --- |
| Colors | Mixed Tailwind + finance-shell CSS | `finance_tokens.dart` | `hasim_colors.dart` | mixed |
| Fonts | page-level | google_fonts | hasim_typography | google_fonts |
| Spacing / radius | ad hoc + finance shell | tokens | hasim_spacing / radius | ad hoc |
| Buttons / inputs / cards | duplicated per module | shared widgets | hasim_widgets | hasim_widgets |
| Theme light/dark | finance shell styled; system theme UNKNOWN on Blade | Light/Dark/System implemented | cashier theme files | UNKNOWN completeness |

**Inconsistencies:** three visual languages (communication layout, financial layout, POS layout) plus three Flutter apps. Dual finance sidebars (Blade vs Flutter) already drifted in product history.

---

## 3. Dark / light (static)

| Surface | Light | Dark | System |
| --- | --- | --- | --- |
| Finance Flutter | PASS (code present) | PASS (tokens + ThemeData) | PASS (`themeModeProvider`) |
| Blade finance | PARTIAL | UNKNOWN / likely BROKEN | UNKNOWN |
| Blade inbox/POS | PARTIAL | UNKNOWN | UNKNOWN |
| Cashier | PARTIAL | UNKNOWN without runtime | UNKNOWN |
| Chat | PARTIAL | UNKNOWN | UNKNOWN |

Contrast, charts, dropdowns: UNKNOWN — Requires runtime verification.

---

## 4. UX / UI defects (code-derived)

| Issue | Status | Evidence |
| --- | --- | --- |
| Dual finance UI | High debt | Blade + Flutter |
| Channel “connected” without backend | Dead / misleading UI | Instagram/Messenger cards |
| Legal placeholders | Incomplete | `[البريد الإلكتروني الرسمي]` |
| Website builder removed | Dead nav risk | retirement migration 2026-09-12 |
| Hardcoded demo risk | Tests forbid `Foreign Giant` on dashboard | leftover sample data possible |
| Missing export | Reports hub “export not started” (status doc; ExportController exists on API — verify UI) | Partial |
| Loading / empty / error | Chat has `empty_state`, `async_body`; Blade mixed | Partial |
| Responsiveness | Finance tests exist for dashboard; cashier table UX tests | Partial |

---

## 5. Findings

### AUD-FE-001
- Severity: Medium
- Title: Three design systems, no shared package
- Location: `resources/views/layouts/*`, `apps/*/lib/core/theme`
- Impact: Inconsistent product; slower QA
- Recommendation: Do not merge apps; share tokens doc only
- Effort: L
- Priority: P3

### AUD-FE-002
- Severity: Medium
- Title: Blade finance dark mode not evidenced
- Location: `finance-shell-styles.blade.php`
- Impact: System dark OS → unreadable if CSS is light-only
- Recommendation: Runtime pass
- Effort: M
- Priority: P2

UNKNOWN — Requires browser verification of every Blade module (this audit did not click the app).



---


# UX-UI-AUDIT

Audit date: 2026-09-13  
First-time-user review from information architecture, not a full click-through.

---

## 1. Confusing by design (evidence)

| Confusion | Why |
| --- | --- |
| Three apps + Blade for the same company | User does not know which icon is “حاسم” |
| Finance exists twice (web + Flutter) | Sidebar parity was already a product fight |
| Channels show Instagram/Messenger | Settings `connected` flag ≠ working pipes |
| Copilot looks like AI | Keyword SQL |
| Dashboard “كم ربحنا؟” vs ledger P&L | Documented divergence |
| Plans vs merchant KYC | Paying SaaS ≠ allowed to collect GMV (`docs/product.md`) |
| Accountant invite becomes member | Enum mapping |
| Website/appointments removed | Old bookmarks / docs / tests |
| Legal pages with brackets | Unprofessional + non-compliant feel |
| Public QR menu | Anyone with slug can order food |
| POS offline vs “cloud invoice” | Cashier may think sale is final before sync |

---

## 2. Onboarding

Open registration → workspace → plan. No activity-type gate. Good for speed; weak for KYC education.

Missing: guided first invoice, WhatsApp connect checklist, “you cannot collect card payments until verified.”

---

## 3. Destructive actions

Finance cancel/reverse/delete exist on API. UNKNOWN whether every Blade/Flutter screen has confirm + reason.

---

## 4. Theme

Finance Flutter dark mode implemented in code. Blade/cashier/chat dark: UNKNOWN / PARTIAL.

---

## 5. Finding

### AUD-UX-001
- Severity: Medium
- Title: Product vocabulary and surfaces are not one product in the user’s head
- Impact: Support load, mis-posting, abandoned onboarding
- Recommendation: One workspace home that launches modules with honest status badges
- Priority: P2



---


# MOBILE-AUDIT

Audit date: 2026-09-13  
Apps: `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`.

---

## 1. Common stack

- Flutter SDK ^3.9, Riverpod, go_router, dio
- `flutter_secure_storage` for tokens
- `google_sign_in`
- Sanctum APIs with `X-Workspace-Id`
- No published store artifacts (`.apk` / `.ipa`) in tree

---

## 2. Per app

| Topic | hasim (chat) | hasim_finance | hasim_cashier |
| --- | --- | --- | --- |
| Navigation | `app_router.dart`, shell | `finance_shell.dart` | `app_router.dart` + stations |
| Auth | login/forgot/reset/social | login/google/forgot | login/PIN/local unlock |
| Secure storage | yes | `secure_store.dart` | yes |
| API | `/api/mobile/v1` | `/api/finance/v1` | `/api/cashier/v1` |
| Refresh tokens | not first-class | not first-class | not first-class |
| Offline | Drift/Hive outbox | online client | **first-class** local POS |
| Deep links | `notification_deep_link.dart` | UNKNOWN | UNKNOWN |
| Push | `push_service.dart` + device API | UNKNOWN | UNKNOWN |
| Permissions | OS photo/file pickers | file picker | storage, printer |
| Lifecycle | standard Flutter | standard | PIN after kill (docs) |
| Tests | present under `test/` | sidebar/visual tests | `pos_local_core_test.dart` etc. |
| Windows desktop | build artifacts exist | `windows/` runner | `windows/` runner |

---

## 3. Findings

### AUD-MOB-001
- Severity: High
- Title: Tokens in secure storage for 60 days
- Location: mobile/cashier auth services + Sanctum
- Impact: Rooted device / backup extraction
- Recommendation: Biometric re-auth; wipe on logout-all
- Effort: M
- Priority: P1

### AUD-MOB-002
- Severity: High
- Title: Chat local DB holds customer messages
- Location: `apps/hasim/lib/core/local_db/inbox_database.dart`
- Impact: Device theft = inbox PII
- Recommendation: SQLCipher / encrypt Drift; remote wipe
- Effort: L
- Priority: P1

### AUD-MOB-003
- Severity: Medium
- Title: Flutter test run on this Windows host previously failed due to `%PROGRAMFILES(X86)%`
- Location: environment, not assertions
- Impact: Mobile regressions not gated
- Recommendation: CI on Linux/macOS images
- Effort: S
- Priority: P1

### AUD-MOB-004
- Severity: Medium
- Title: Deep links / push not verified end-to-end
- Location: `notification_deep_link.dart`, `SendPushNotificationJob`
- Status: UNKNOWN — Requires runtime verification

Finance app correctly avoids local tax/GL (good). Cashier intentionally violates that for offline (see POS-AUDIT).



---


# DESKTOP-AUDIT

Audit date: 2026-09-13

---

## 1. What exists

Not Electron/Tauri/WPF. All three Flutter apps include **Windows** runners:

- `apps/hasim/windows` (build native assets present)
- `apps/hasim_finance/windows/runner/*`
- `apps/hasim_cashier/windows/runner/*`

Downloads page: `routes/web.php` `/downloads` + `resources/views/downloads.blade.php`.

No first-party installer pipeline (Squirrel/MSIX/Sparkle) found in repo root.

---

## 2. Checklist

| Topic | Status | Evidence |
| --- | --- | --- |
| Packaging | Partial | Flutter windows CMake runners |
| Auto-update | Missing | no sparkle/msix updater package in pubspecs |
| Local storage | Present | same as mobile (secure storage, Drift, Hive) |
| Filesystem | Present | path_provider, file_picker, cashier backup |
| IPC | Flutter engine defaults | no custom native plugin audit |
| Permissions | OS user account | UNKNOWN sandbox |
| Secrets | flutter_secure_storage | Windows credential storage UNKNOWN quality |
| Printer | cashier `printer_service.dart` | UNKNOWN device QA |
| Crash handling | none first-party (no Sentry in pubspec) | Missing |
| Offline | cashier yes; finance no; chat cache | see POS/CHAT |

---

## 3. Findings

### AUD-DESK-001
- Severity: High
- Title: Desktop shipped without update channel
- Location: apps `windows/`, pubspecs
- Impact: Vulnerable clients stay in restaurants/offices forever
- Recommendation: Signed MSIX + forced min version
- Effort: L
- Priority: P1

### AUD-DESK-002
- Severity: High
- Title: Cashier desktop holds a second ledger
- Location: local SQLite + backup AES (POS docs)
- Impact: Stolen PC = sales + catalog + maybe backup password
- Recommendation: Disk encryption requirement; short PIN + remote disable
- Effort: M
- Priority: P1

### AUD-DESK-003
- Severity: Medium
- Title: No crash telemetry
- Location: pubspecs lack sentry_flutter / firebase_crashlytics
- Impact: Production failures invisible
- Effort: S
- Priority: P2

UNKNOWN — Requires runtime verification: installer, auto-start, printer, backup password UX, Windows Defender false positives.



---


# PERFORMANCE-AUDIT

Audit date: 2026-09-13  
No profilers were run. Bundle sizes UNKNOWN.

---

## Top 20 performance problems

| # | Problem | Location / evidence | Severity |
| --- | --- | --- | --- |
| 1 | Queue/cache/session default to **database** | `docs/PRODUCTION.md` | High |
| 2 | God services (invoice/POS) load large graphs | `InvoiceService`, `PosOrderService` | High |
| 3 | Dashboard vs ledger duplicate aggregates | finance dashboard + `LedgerReportService` | High |
| 4 | AI embeds up to 20 products JSON every reply | `AIService` | Medium |
| 5 | Finance search hits many entity tables | `FinanceSearchService` | Medium |
| 6 | Blade N+1 historically; only some bounded | invoice `withSum` repair | Medium |
| 7 | No report caching | status: “Report caching” remaining | Medium |
| 8 | Flutter google_fonts at runtime | all three apps | Medium |
| 9 | Chat image cache + video_player | `apps/hasim` | Medium |
| 10 | Cashier catalog images cached_network_image | cashier pubspec | Medium |
| 11 | Vite/Tailwind not code-split per module | single `npm run build` | Medium |
| 12 | 317 Blade views, multiple fat layouts | finance + communication + POS | Medium |
| 13 | Sync pull/push unbounded UNKNOWN | `PosSyncPullService` | High |
| 14 | Email campaign jobs per recipient | `SendCampaignRecipientJob` | Medium |
| 15 | SQLite DATE_FORMAT workaround | status doc | Low (tests) / High (MySQL drift) |
| 16 | Missing pagination on some web indexes | UNKNOWN all lists | Medium |
| 17 | Copilot SQL on demand | `FinanceCopilotService` | Medium |
| 18 | Integrity command full-table sums | `routes/console.php` | Low |
| 19 | Three Flutter engines if all desktops open | ops | Low |
| 20 | No APM / slow-query log in repo | composer.json | High (ops) |

UNKNOWN — Requires runtime verification: TTFB, PHP-FPM workers, MySQL explain, Flutter jank, Vite bundle KB.

---

## What is already improved

- Invoice payment status refresh uses `withSum` (repair audit + test bound).
- Inventory create form limited 100 products / 200 variants.
- Scheduler `withoutOverlapping`.
- Cashier stress numbers exist in POS readiness docs (CI SQLite, not production MySQL).



---


# QA-AUDIT

Audit date: 2026-09-13  
PHPUnit was **not** executed in this audit (no-fix / no-run-repair rule). Historical claim: 284 passed / 2 skipped on 2026-09-02.

---

## 1. Inventory

| Suite | Location | Count (files) |
| --- | --- | --- |
| PHP Feature + Unit | `tests/` | 162 `*Test.php` |
| Money / tax / e-invoice harness | `tests/Unit/Money`, `Unit/Finance`, `Unit/EInvoicing` | many |
| Tenancy / security | `Tenancy/WorkspaceIsolationTest`, `Security/*` | present |
| Finance | `tests/Feature/Feature/Finance/*` | large |
| POS | `tests/Feature/Feature/Pos/*` | present |
| Mobile API | `tests/Feature/Mobile/*` | present |
| Subscriptions | `Subscription*Test` | present |
| Flutter chat | `apps/hasim/test` | present |
| Flutter finance | `apps/hasim_finance/test` | sidebar/visual |
| Flutter cashier | `apps/hasim_cashier/test` including `pos_local_core_test.dart` | present |
| E2E browser | none first-party (no Dusk/Playwright in composer/package) | **Missing** |
| Load tests | listed as later phase I | **Missing** |

Coverage percent: UNKNOWN — no `coverage` artifact in this pass.

---

## 2. What is tested well

- Workspace IDOR 404s on many finance writes
- OTP enumeration, role hierarchy, API keys, inventory authz
- Money integer math
- Journal immutability / period guard (PlatformCore / FinancialCore)
- Tax engine + e-invoice cryptographic **harness** (not live ZATCA)
- POS module + cashier UX + table QR
- Mobile auth/workspace
- Secure upload unit test
- Webhook signature/replay unit tests

---

## 3. Dangerous gaps

| Gap | Why it matters |
| --- | --- |
| Full suite not re-run 2026-09-13 | Website builder retirement + quotes + finance UI may have broken tests |
| No MySQL / InnoDB lock tests | Double payment / stock races |
| No E2E across Flutter ↔ Laravel | Offline sync, Google login |
| No chaos (worker down, Redis down) | Business continuity unknown |
| Pint non-blocking / no PHPStan | Quality gate weak |
| CI workflow **absent** in this tree | Nothing fails the merge |
| Flutter Windows env broken historically | Mobile QA skipped |
| Central email 2 tests skipped | Inbox send path |
| Policies exist for some models only (12) | Many finance models rely on controller checks |

---

## 4. Error handling (logical)

| Failure | User message | Retry | Log | Error ID |
| --- | --- | --- | --- | --- |
| API 401 mobile/cashier | Arabic session expired | re-login | Laravel | no correlation id |
| Finance API | `FinanceApiErrorRenderer` codes | unknown | yes | `code` string, not UUID |
| Validation | 422 errors map | n/a | n/a | n/a |
| Payment webhook fail | job fail log (no payload) | queue retry UNKNOWN | `Queue::failing` | no Sentry |
| Upload fail | RuntimeException Arabic | user retry | unknown | no |
| DB down | 500 generic | no | yes | no |

---

## 5. Finding

### AUD-QA-001
- Severity: High
- Title: Quality gates described in docs are not in this workspace
- Location: claimed `.github/workflows/ci.yml` vs missing `.github`
- Impact: Production can ship broken isolation
- Priority: P0



---


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



---


# PRIVACY-AUDIT

Audit date: 2026-09-13

---

## 1. Legal pages

| Document | Route | Status |
| --- | --- | --- |
| Privacy | `/privacy` | Incomplete — placeholders |
| Terms | `/terms` | Incomplete |
| Cookies | `/cookies` | Incomplete |
| Acceptable Use | `/acceptable-use` | Incomplete |
| Refund | placeholder in `config/legal.php` | Missing as policy |
| Consent / cookie banner | not evidenced | Missing / UNKNOWN |

`config/legal.php` placeholders: official email, effective date, tax number, retention, jurisdiction, refund, trial length.  
Company name, phone, CR, Riyadh address **are** filled.

Classification: **Needs legal review before public launch.** Not “present and complete.”

---

## 2. Data classes

| Class | Stored where | Notes |
| --- | --- | --- |
| PII (name, phone, email) | `users`, `customers`, email contacts | required for product |
| Financial | invoices, payments, journals, payroll | high sensitivity |
| Chat | messages, attachments, WhatsApp | high sensitivity |
| KYC documents | private disk | good |
| AI logs | full prompts | **excessive** |
| Email logs | recipient/subject/error, unscoped model | risk |
| Device tokens | `device_push_tokens` unscoped | risk |
| Analytics | workspace analytics service | MVP |
| Local Flutter DBs | chat + cashier devices | high if stolen |
| Backups | cashier encrypted local; Laravel UNKNOWN | |

---

## 3. Findings

### AUD-PRV-001
- Severity: High
- Title: Legal facts incomplete while pages are public
- Location: `config/legal.php`, `LegalPageController`
- Impact: Regulatory / trust failure in KSA
- Priority: P0

### AUD-PRV-002
- Severity: High
- Title: AI and email logs retain unnecessary content
- Location: `AiLog`, `EmailLog`
- Impact: Over-retention
- Priority: P1

### AUD-PRV-003
- Severity: Medium
- Title: No documented retention/deletion job
- Location: no GDPR/PDPL purge command found
- Impact: Cannot honor deletion requests
- Priority: P1

Logging of passwords/tokens: repair notes say queue fail does not log payloads; OTP not returned. UNKNOWN all `Log::` call sites.



---


# TECH-DEBT

Audit date: 2026-09-13

---

## Critical

| Location | Why it matters | Risk | Cost to fix |
| --- | --- | --- | --- |
| `WorkspaceScopedModel` fail-open scope | Cross-tenant reads | Data breach | M |
| Missing CI / Docker / git in this tree | Cannot release safely | Production catastrophe | M |
| Dual tax + offline pricing | Wrong VAT / sales | Financial loss | L |
| Manager elevation = all finance perms | Privilege | Financial loss | M |
| No DB backup automation | Unrecoverable ledger | Catastrophe | M |
| Legal placeholders live | Launch block | Legal | S |

## High

| Location | Why | Risk | Cost |
| --- | --- | --- | --- |
| `InvoiceService` / `PosOrderService` size | Unreviewable changes | Regression | L (do not split without seams) |
| Unscoped EmailLog / attachments / push tokens | IDOR | PII | S–M |
| `finance_invoices.status` legacy | Dual writers | Stale totals | M |
| Four auth controllers | Drift | Auth hole | M |
| `products` vs `pos_menu_items` | Stock mismatch | Finance | L |
| `ai_logs` full bodies | Privacy | Leak | S |
| Database queue default | Lost jobs | Money/messages | S (ops) |
| Duplicate migration timestamp | Operator rewrite | Deploy break | S (docs only) |
| Accountant role mapping | Wrong ACL | Privilege/UX | M |
| No PHPStan | Silent bugs | Medium | L |
| Flutter local chat DB plaintext | Device theft | PII | L |
| No desktop updater | Forever-old clients | Security | L |
| Website/appointment retirement leftovers | Dead tests/docs | Confusion | S |

## Medium

| Location | Why | Risk | Cost |
| --- | --- | --- | --- |
| Dual finance UIs | Drift | UX | L |
| 3 design systems | Slow UI | Low | L |
| `Money::round` float | Drift | Rounding | S |
| Integrity command float | False ops | Low | S |
| Copilot keyword vs LLM expectation | Trust | Product | S |
| Instagram/Messenger flags | False feature | Support | S |
| Missing listeners (only 2) | Incomplete domain events | Sync | M |
| 12 policies vs 200 controllers | Inconsistent authz | IDOR | L |
| Pint not clean | Style noise | Low | M |
| Quotes exist / old spec stale | Agents follow wrong doc | Process | S |

## Low

| Location | Why | Risk | Cost |
| --- | --- | --- | --- |
| `ExampleTest.php` | Noise | None | S |
| Inspire command | Noise | None | S |
| google_fonts runtime | Perf | Jank | S |
| EN locale incomplete | i18n | Low | M |

---

## Code quality signals (not exhaustively listed)

- TODO/FIXME: UNKNOWN full count (not grepped in this pass without flooding vendor)
- `console.log`: Blade/Vite UNKNOWN
- Circular deps: likely in fat services
- Magic strings: membership roles, account codes 1000/1100/1200…
- Huge functions: invoice create/issue, POS checkout

Do **not** “fix debt” by splitting the monolith or adding a second database.



---


# ROADMAP

Audit date: 2026-09-13  
Order is risk-first. Do not start polish before isolation and money.

---

## PHASE 0 — Emergency Security

**Goal:** Stop plausible cross-tenant and unauthenticated cost/abuse holes.  
**Tasks:**

- Fail-closed `WorkspaceScopedModel` when a user/request exists without context
- Scope `EmailLog` / attachments / recipients / push tokens or lock queries
- Webhook fail-closed if signing secret empty in production
- Hide or rate-budget public assistant + menu AI
- Remove default platform admin password path
- Restore CI that runs `php artisan test`

**Dependencies:** none  
**Risk:** Breaking jobs that relied on fail-open scope — add explicit `forWorkspace`  
**Outcome:** No silent unscoped tenant queries on HTTP

---

## PHASE 1 — Data Integrity

**Goal:** One number for a sale.  
**Tasks:**

- Server-authoritative tax on POS accept/sync
- Unique `client_reference` + sync replay tests on MySQL
- Align cashier invoice / order / finance invoice links
- Stop float integrity commands
- Backup + restore drill for MySQL and `storage/app/private`

**Dependencies:** Phase 0 context rules  
**Risk:** Offline POS needs a migration window  
**Outcome:** No double sale; restore tested

---

## PHASE 2 — Auth / Authorization

**Goal:** Roles mean what the UI says.  
**Tasks:**

- Split `financePermissionMap` so `$elevated` is not all-powerful
- Password-reset revokes Sanctum tokens
- 2FA for owner + platform admin
- Membership enum vs accountant mapping decision (additive migration only if needed)
- Session cookie flags verified in staging

**Dependencies:** Phase 0  
**Risk:** Owners locked out if elevation removed carelessly  
**Outcome:** Least privilege for money mutations

---

## PHASE 3 — Finance Integrity

**Goal:** Dashboard = ledger; VAT path is one engine.  
**Tasks:**

- KPI cards from `LedgerReportService`
- Unify POS tax into `TaxCalculationService`
- MySQL concurrent payment/stock tests
- Document ZATCA as foundation only until official integration
- Keep AI read-only

**Dependencies:** Phase 1  
**Risk:** Dashboard test strings (`كم بعنا؟`) must stay honest  
**Outcome:** Close the books from one P&L

---

## PHASE 4 — Cross-App Integration

**Goal:** One customer, one product, honest channels.  
**Tasks:**

- Customer `client_reference` enforced across cashier/finance/chat
- Menu item always points at `product_id`
- Hide Instagram/Messenger until backends exist
- Define invoice vs POS order vs receipt in UX copy
- Chat outbox idempotency E2E

**Dependencies:** Phase 1  
**Risk:** Data backfill  
**Outcome:** No duplicate customers/products as the happy path

---

## PHASE 5 — Stability

**Goal:** Workers and schedulers are production facts.  
**Tasks:**

- Redis queue/cache/session
- Supervisor for `queue:work` + `schedule:run`
- Failed-job alerts
- WhatsApp/email health badges
- Remove leftover website/appointment tests

**Dependencies:** Phase 0–1  
**Risk:** Local DX if Redis forced in `.env.example`  
**Outcome:** Jobs do not silently die

---

## PHASE 6 — Performance

**Goal:** Survive a busy restaurant Friday.  
**Tasks:**

- Paginate remaining indexes
- Cache ledger reports
- Bound sync pull pages
- Explain MySQL slow queries
- Stop shipping unused Blade CSS globally if measured

**Dependencies:** Phase 5 metrics  
**Risk:** Cache vs closed period  
**Outcome:** p95 known

---

## PHASE 7 — UX/UI

**Goal:** One mental model.  
**Tasks:**

- Workspace home with module status
- Honest AI/copilot labels
- Complete legal placeholders
- Blade dark mode or explicitly light-only
- Confirmations on reverse/cancel

**Dependencies:** Phase 2–3 for permission-aware nav  
**Risk:** Dual finance UI — pick a source of chrome, do not invent nav  
**Outcome:** First-hour success

---

## PHASE 8 — Testing

**Goal:** Isolation and money cannot regress.  
**Tasks:**

- CI green on PHP + selected Flutter
- MySQL integration job
- Sync replay suite
- PHPStan baseline
- Do not claim coverage % without clover

**Dependencies:** Phase 0 CI  
**Risk:** Flaky SQLite vs MySQL  
**Outcome:** Merge blocked on isolation tests

---

## PHASE 9 — Deployment

**Goal:** Repeatable staging + prod.  
**Tasks:**

- Docker or equivalent image
- Git + tags (this tree currently has no git)
- TLS, secrets manager, log ship
- Rollback runbook
- Mobile/desktop signed builds + min version

**Dependencies:** Phase 5  
**Risk:** Windows cashier packaging  
**Outcome:** Someone else can deploy from docs + image

---

## PHASE 10 — Polish

**Goal:** Only after money is safe.  
**Tasks:**

- EN parity
- Design token cleanup
- CSV exports
- Bank CSV import
- Forecasting/OCR (explicitly later in spec)

**Dependencies:** Phases 0–9  
**Risk:** Scope creep  
**Outcome:** Product feel, not survival

