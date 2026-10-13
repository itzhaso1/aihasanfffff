# HASEM Implementation Status

Status values: **Existing**, **Strengthened**, **New**, **Partial**, **Not started**, **Removed**.

This file tracks the **active** HASEM platform (web + mobile API + `apps/hasim`). Hasim Finance and Hasim Cashier are **Removed** — no routes, apps, or APIs remain in this repository.

---

## Active product matrix

| Area | Status | Notes |
| --- | --- | --- |
| Tenancy & RBAC | Existing | `workspace_id` scoping, Spatie teams, platform admin guard |
| Auth (web + mobile) | Existing | Session, Sanctum, OAuth, OTP |
| Conversations & inbox | Existing | WhatsApp, worker chat, realtime, AI auto-reply |
| Chat catalog | Existing | Mobile + web CRUD, AI retrieval, domain separation tests |
| Commerce & orders | Existing | Products, inventory, orders, payments; chat commerce tax uses `App\Enums\Finance\*` |
| Email CRM | Existing | Mailboxes, branding, template library, jobs |
| Subscriptions / plans | Existing | SaaS billing for workspaces |
| AI (inbox / assistant) | Existing | Provider abstraction, guardrails, usage limits |
| Flutter Hasim app | Existing | `apps/hasim` → `/api/mobile/v1` |

---

## Removed products (this repository)

| Product | Status | Notes |
| --- | --- | --- |
| Hasim Finance (`apps/hasim_finance`, `/api/finance/v1`, Blade finance ERP) | Removed | No app directory, routes, controllers, or tests |
| Hasim Cashier (`apps/hasim_cashier`, `/api/cashier/v1`, offline POS) | Removed | No app directory, routes, controllers, or tests |
| E-invoicing / ZATCA stack (PHP + bundled XSD) | Removed | Docs and `resources/einvoicing` removed; no runtime code remained |

---

## Database / legacy schema

Historical migrations that create `finance_*` and `pos_cashier_*` tables **remain in the migration history** so existing databases and `migrate:fresh` in CI keep a valid chain. Dropping those tables in production requires a **separate deployment migration** — not executed as part of product removal. See `docs/database/FINANCE_CASHIER_SCHEMA_RETIREMENT.md`.

---

## Verification

```bash
php artisan test --configuration phpunit.critical.xml
cd apps/hasim && flutter test
```
