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
