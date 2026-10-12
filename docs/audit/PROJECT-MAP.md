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
