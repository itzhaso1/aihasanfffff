# HASEM — Executive Summary (Discovery Audit)

**Audit date:** 2026-10-12  
**Scope:** Evidence-based static discovery of `/home/deploy/aihasan/aihasan`  
**Method:** Manifests, routes, models, services, migrations, tests, docs cross-check. No production DB, no test execution, no code changes.

---

## What HASEM is (documented intent)

HASEM is designed as a **modular Laravel monolith** (PHP 8.3, Laravel 13) with **one database** and **`workspace_id` tenancy**. Client surfaces include Blade web, platform admin, mobile API (`/api/mobile/v1`), and (per specification) finance API (`/api/finance/v1`) and cashier API (`/api/cashier/v1`), plus Flutter apps `hasim`, `hasim_finance`, and `hasim_cashier`.

**Evidence:** `AGENTS.md`, `docs/HASEM_MASTER_PLATFORM_SPEC.md`, `composer.json` (PHP `^8.3`, `laravel/framework` `^13.17`).

Official product rule: **three functional catalogs** — Finance (`products`), Cashier (`pos_menu_items`), Chat (`chat_catalog_items`) — do not inherit from each other.

**Evidence:** `docs/audit/DOMAIN-CATALOG-SEPARATION.md`, `docs/HASEM_MASTER_PLATFORM_SPEC.md` §2.

---

## Critical environmental finding (this checkout)

The **checked-out tree on this server is not a complete mirror** of the architecture described in master docs and prior audits.

| Expected (docs / migrations / tests) | Present in PHP/routes on disk |
| --- | --- |
| `routes/finance-api.php`, `routes/cashier.php` | **Not found** (`routes/` has 8 files only) |
| `/api/finance/v1/*` route registration | **Not registered** in `bootstrap/app.php` / `routes/api.php` (only `mobile/v1` group) |
| `app/Models/Product.php`, finance models/services | **Not found** (~82 models; no `Product`, no `app/Services/Finance/`) |
| Finance workspace web routes in `routes/web.php` | **No matches** for finance/invoice/ledger |
| `apps/hasim_finance`, `apps/hasim_cashier` | **Not present** under `apps/` (only `apps/hasim/`) |
| ~226 tables in migrations | Migrations **present** (including `finance_*`, `pos_*`) |

**Status:** **Unknown / requires manual verification** — whether this is an intentional deployment slice, an incomplete clone, or files excluded from sync.

**Implication:** Implementation maturity for Finance ERP and POS **cannot be verified from application code in this tree**; it must be inferred from migrations + documentation + tests (which reference classes that may be absent here).

**Evidence:** `find apps/` → only `hasim`; `ls app/Models` → no `Product.php`; `glob routes/finance-api.php` → 0 files; `grep finance routes/web.php` → empty.

---

## What is clearly implemented in this checkout

### Communication & inbox (Chat domain)

- Unified inbox UI (Blade), conversations, messages, handoff, worker inbox reuse.
- **Evidence:** `resources/views/workspace/conversations/index.blade.php`, `app/Http/Controllers/Workspace/ConversationController.php`, `app/Http/Controllers/Worker/WorkerInboxController.php` (returns same view).
- Mobile API v1 for conversations, email, stories, catalog, commerce carts/orders, AI settings/employees.
- **Evidence:** `routes/mobile.php`, `routes/api.php` lines 94–96.

### Chat catalog & commerce order engine (Chat catalog → orders)

- Models: `ChatCatalogItem`, variants, digital inventory, templates.
- Services: `ChatCatalogRetrievalService` (AI grounding), `CommerceOrderService` (explicitly **not** legacy finance `OrderService`).
- **Evidence:** `app/Services/Commerce/CommerceOrderService.php` docblock lines 25–30; `app/Services/Chat/ChatCatalogRetrievalService.php` (referenced in tests).

### Inbox AI

- Job-driven replies: `ProcessAIResponse`, inbox agent tools, platform-wide model via `PlatformAiConfiguration`.
- **Evidence:** `app/Jobs/ProcessAIResponse.php`; `app/Services/Platform/PlatformAiConfiguration.php`.

### Email (workspace-connected mailboxes)

- Models: `EmailAccount`, `EmailMessage`, campaigns, contacts; jobs `SyncEmailInboxJob`, `SendEmailMessageJob`.
- **Evidence:** `app/Models/EmailAccount.php`, `app/Jobs/SyncEmailInboxJob.php`, `app/Services/Email/*`.

### Channels & webhooks

- WhatsApp, Meta (Facebook/Instagram), Telegram, TikTok webhook controllers; outbound delivery jobs.
- **Evidence:** `routes/web.php` webhook routes; `app/Http/Controllers/Webhook/*`; `app/Jobs/ProcessIncomingWhatsAppMessage.php`.

### Workforce (independent workers)

- Engagements, inbox capability gating, mobile worker API, platform admin verification/payout routes.
- **Evidence:** `app/Models/Worker/*`, `routes/mobile.php` worker prefix; `routes/platform.php` worker-* routes.

### Tenancy & security primitives

- `WorkspaceScopedModel`, `ResolveWorkspaceContext`, `EnsureWorkspaceMembership`, `EnsureFeatureAccess`, Spatie teams.
- **Evidence:** `app/Models/WorkspaceScopedModel.php`, `app/Http/Middleware/ResolveWorkspaceContext.php`, `bootstrap/app.php` middleware aliases.

### Subscriptions & platform admin

- SaaS plans/subscriptions (models present); isolated `platform_admin` guard and `/platform/*` routes.
- **Evidence:** `routes/platform.php`, `app/Http/Controllers/Platform/AuthController.php`.

---

## Flutter clients (this checkout)

| App | Path | SDK | API (intended) |
| --- | --- | --- | --- |
| Hasim (chat) | `apps/hasim/` | Dart `^3.9.0` (`pubspec.yaml`) | `/api/mobile/v1` |
| Hasim Finance | Documented only | — | `/api/finance/v1` — **app missing here** |
| Hasim Cashier | Documented only | — | `/api/cashier/v1` — **app missing here** |

**Evidence:** `apps/hasim/pubspec.yaml`; `README.md` lines 49–50 vs `ls apps/`.

---

## Tests & CI (static)

- **~293** PHPUnit test files under `tests/` (file count only; **not executed** in this audit).
- CI: `.github/workflows/ci.yml` — Pint, PHPUnit (SQLite in-memory per `phpunit.xml`).
- **Not verified:** pass/fail on this checkout (dev dependencies may be incomplete; `php artisan test` was unavailable in one environment session).

---

## Principal risks (for owners)

1. **Documentation vs disk drift** — Finance/POS described as production-ready in `docs/` but PHP/routes absent locally → deployment/replica confusion.
2. **Schema wider than code** — 226 migration tables including finance/POS; running app without matching PHP may be broken or partial.
3. **Commerce payments explicitly gated** — `PaymentService::createPaymentLink` throws for `order.source === 'commerce'`.
4. **Platform AI model allow-list** — Misconfigured platform model breaks pages loading presets (`PlatformAiConfiguration::resolvePair`).
5. **Cross-workspace IDOR** — Mitigated by design (`WorkspaceScopedModel`, dedicated tests); must remain on all new routes.

---

## Audit deliverables

All reports: `docs/audit/discovery-2026-10-12/`

**Confirmation:** No application source, schema, config, dependencies, or production data was modified during this audit.
