# HASEM — agent map

Laravel 13 / PHP 8.3 modular monolith. One product, one database, `workspace_id` tenancy. Do not split into new apps.

## Surfaces

| Surface | Path / contract | Notes |
| --- | --- | --- |
| Laravel web (Blade) | `routes/web.php`, `resources/views` | Session auth |
| Platform admin | `routes/platform.php` | Isolated `platform_admin` guard |
| Mobile API | `routes/mobile.php` → `/api/mobile/v1` | Sanctum + `X-Workspace-Id` |
| Finance API | `routes/finance-api.php` → `/api/finance/v1` | Used by `apps/hasim_finance` |
| Cashier API | `routes/cashier.php` → `/api/cashier/v1` | Used by `apps/hasim_cashier` |
| Hasim chat app | `apps/hasim` | Inbox / WhatsApp / email / customers |
| Hasim finance app | `apps/hasim_finance` | No local tax/totals/GL math |
| Hasim cashier app | `apps/hasim_cashier` | Offline catalog + pending orders |

## Read first

- Architecture + non-negotiables: `docs/HASEM_MASTER_PLATFORM_SPEC.md`
- Feature status (what exists vs later): `docs/HASEM_IMPLEMENTATION_STATUS.md`
- Cursor rules: `.cursor/rules/`

## Work style

- Prefer existing services under `app/Services/{Domain}` over new controllers that embed domain logic.
- Tenant models extend `WorkspaceScopedModel`. Cross-workspace access must 404.
- Money math uses `App\Support\Money\Money` (integer minor units). No raw floats.
- Additive migrations only. Keep APIs and invoice statuses backward compatible.
- Tests: `php artisan test` (SQLite in-memory). Add isolation / IDOR coverage for tenant writes.
