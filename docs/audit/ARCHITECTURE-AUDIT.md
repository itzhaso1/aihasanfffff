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
