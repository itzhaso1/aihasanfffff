# HASEM Master Platform Specification

This document describes the **current** HASEM architecture. HASEM is one Laravel monolith: one database, `workspace_id` tenancy, Blade web UI, platform admin, mobile API, queues, and scheduled jobs.

Hasim Finance and Hasim Cashier were removed from the product surface. Legacy database tables from those modules may still exist in deployed environments until a dedicated retirement migration is applied (see `docs/database/FINANCE_CASHIER_SCHEMA_RETIREMENT.md`).

---

## 1. Architecture

### Style

Modular Laravel monolith.

| Layer | Responsibility |
| --- | --- |
| Presentation | Blade workspace UI, Mobile API (`/api/mobile/v1`), platform admin |
| Application | Controllers, form requests, workspace middleware, feature gates |
| Domain services | Conversations, chat commerce, CRM, email, AI, billing/subscriptions |
| Infrastructure | Eloquent + `WorkspaceScopedModel`, Redis/cache, queues, storage, webhooks, payment adapters |

### Non-negotiable rules

1. Monetary arithmetic for commerce uses integer minor units via `App\Support\Money\Money` where applicable. Do not use raw floats for money math.
2. Every tenant-owned query must be workspace-scoped. Cross-workspace access must 404.
3. AI may read authorized workspace data and suggest actions. It must not mutate protected records without an explicit, authorized workflow.
4. Additive migrations only for production. Do not drop legacy finance/cashier tables in this repo without a coordinated deployment plan.

### Tenancy model

- Canonical tenant: `workspaces` (`individual`, `company`, `store`).
- Membership: `workspace_users` with membership roles (`owner`, `admin`, `manager`, `agent`, …).
- Isolation: `workspace_id` on tenant tables + `WorkspaceScopedModel` global scope + membership middleware + Spatie permission teams.
- Feature flags: `workspace_feature_flags` plus plan entitlements.

### Identity and authorization

- User auth: Laravel session (web) + Sanctum (API).
- Platform admin: isolated guard/panel (`routes/platform.php`).
- RBAC: Spatie roles/permissions, team-aware by workspace.
- Server-side authorization is required. Hiding a nav link is not access control.

---

## 2. Domain map (inside one product)

```text
HASEM PLATFORM
├── Identity / Tenancy / RBAC / Customers (shared)
├── CHAT & INBOX
│   ├── Unified inbox / WhatsApp / worker chat / stories
│   └── `/api/mobile/v1/*` + apps/hasim
├── COMMERCE (chat-attached)
│   ├── Leftover `chat_catalog_items` rows (orders/carts/worker product FKs)
│   ├── Inventory, orders, payments (workspace-scoped)
│   └── Tax enums under `App\Enums\Finance\*` used by chat commerce tax resolution only
├── EMAIL CRM
│   ├── Workspace mailboxes, branding, template library
│   └── Queue-based sync/send
├── Billing / Subscriptions / Plans
└── Infrastructure (queues, scheduler, observability)
```

### Catalog isolation

| Catalog | Source of truth | Consumers |
| --- | --- | --- |
| Chat Catalog product | Removed. No storefront, settings, or AI retrieval. | — |
| Leftover commerce items | `chat_catalog_items` (and variants) | Commerce carts/orders and worker product pickers |
| Commerce products | `products` (and related commerce tables) if present | Orders, payments, inventory — not a separate Flutter ERP/POS client |

Inbox AI does not search a product catalog. `search_products` returns an empty list. WhatsApp is the primary live inbox channel unless another channel is explicitly wired in code.

---

## 3. API surfaces

| Surface | Prefix / path | Auth |
| --- | --- | --- |
| Web | `routes/web.php` | Session |
| Platform | `routes/platform.php` | `platform_admin` guard |
| Mobile | `/api/mobile/v1` via `routes/mobile.php` | Sanctum + `X-Workspace-Id` |

There is **no** `/api/finance/v1` or `/api/cashier/v1` in this repository.

---

## 4. Testing

- Default: `php artisan test` (SQLite in-memory).
- CI critical suite: `phpunit.critical.xml` / `phpunit.critical.mysql.xml`.
- Add tenant isolation / IDOR tests for new write paths.

---

## 5. Further reading

- Flutter UI: `docs/HASIM_FLUTTER_DESIGN_SYSTEM.md`
- Feature status: `docs/HASEM_IMPLEMENTATION_STATUS.md`
- Production architecture notes: `docs/architecture/production-architecture.md`
