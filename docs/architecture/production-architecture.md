# HASEM Production Architecture (Laravel)

## 1) System Architecture (High-Level)

### Architecture Style
- **Backend Monolith (Modular Laravel)** with clear domain boundaries.
- **Shared DB + Shared Tables + `workspace_id`** multi-tenancy.
- **Strict workspace context propagation** across API, services, jobs, events, notifications, webhooks, caches, and storage paths.

### Core Layers
- **Presentation Layer**: REST API + Blade MVC interfaces.
- **Application Layer**: Controllers, Form Requests, Actions/Services.
- **Domain Layer**: Business services (Workspace, Auth, Inventory, Orders, Payments, AI, WhatsApp, Subscription).
- **Infrastructure Layer**: Eloquent, Queues, Cache/Redis, Storage, Webhook handlers, Gateway adapters.

### Main Actors
- Platform Admin (completely isolated auth guard/panel)
- Individual Workspace users
- Company/Store Workspace users (Owner/Admin/Manager/Agent)

---

## 2) Workspace Architecture

### Canonical Tenant Entity
- We standardize on **`workspaces`** as the tenant boundary.
- `workspaces.type`:
  - `individual`
  - `company`
  - `store`

### Membership Model
- `workspace_users` pivot controls membership and role per workspace.
- A single user may belong to multiple workspaces.
- Workspace ownership tracked by `workspaces.owner_user_id`.

### Isolation Rule
- Any business table that belongs to a tenant includes `workspace_id`.
- Query access to tenant data is denied unless workspace context is resolved and membership is valid.

---

## 3) Multi-Tenancy Strategy Decision

## Selected Strategy
**Shared Database + Shared Tables + `workspace_id` + context-driven enforcement**

### Why this strategy
- **Security**: enforced by middleware + policies + model scopes + membership checks + tests.
- **Scalability**: horizontal app scaling, indexed tenant columns, queue partitioning by workspace id.
- **Performance**: composite indexes on (`workspace_id`, domain columns) keep access fast.
- **Maintenance**: single schema evolution path; no N schemas or N databases migration overhead.
- **Cost**: significantly lower infra and ops overhead than database-per-tenant.

### Hard Enforcement Mechanisms
1. `ResolveWorkspaceContext` middleware
2. `EnsureWorkspaceMembership` middleware
3. Workspace-aware global model scope (`WorkspaceScopedModel`)
4. RBAC (Spatie Permission teams configured with `workspace_id`)
5. Policy checks
6. Feature access checks (type + plan + permission)
7. Test suite for cross-workspace access attempts

---

## 4) Individual vs Company/Store Architecture

### Individual Workspace (Personal SaaS)
- AI
- Smart Replies
- Conversations
- WhatsApp (plan-gated)
- Subscription/Usage

### Company/Store Workspace (Commercial SaaS)
- Dashboard
- Products/Categories
- Inventory + movement history
- Customers/CRM
- Orders
- Conversations/Inbox
- AI assistant with catalog context
- Payments/Payment Gateway
- Employees + Roles/Permissions
- Subscription/Analytics

Feature exposure is **backend-enforced**, not menu-based only.

---

## 5) Authentication Architecture

### Supported Methods
1. Email + Password
2. Phone + OTP
3. Google OAuth
4. Facebook OAuth

### Core Components
- `users` + `auth_identities` (provider mapping)
- Sanctum tokens (`personal_access_tokens`) with `workspace_id`
- OTP service (server-side issue/verify with TTL + attempt limits)
- Social token exchange flow using Socialite provider adapters

### Platform Admin Authentication
- Separate model/table/guard:
  - `platform_admins`
  - guard: `platform_admin`
  - routes under `/platform/*`

No shared dashboard auth surface with normal workspace users.

---

## 6) Authorization / RBAC Architecture

- Spatie Permission with **teams mode enabled**.
- Team foreign key set to `workspace_id`.
- Roles are assigned in workspace scope.
- Baseline roles:
  - Owner
  - Admin
  - Manager
  - Agent

Policy + permission + membership checks are cumulative.

---

## 7) Feature Access Architecture

Final decision for any feature:
1. Workspace type baseline (from `config/workspace.php`)
2. Active plan features/limits
3. Workspace feature overrides (`workspace_feature_flags`)
4. Role permission checks

This prevents individuals from reaching commercial modules unless explicitly enabled later.

---

## 8) API Architecture

### API Prefixes
- `/api/auth/*`
- `/api/workspaces`
- `/api/workspace/{workspace}/*` (workspace context required)

### Request pipeline (workspace APIs)
Authenticated user
-> resolve workspace context
-> validate membership
-> validate feature access
-> authorize policy/permission
-> validate request
-> execute business service
-> commit transaction / dispatch jobs

---

## 9) AI Architecture

### Guardrails
- AI never writes DB directly.
- AI returns structured intent/tool call.
- Laravel service executes all writes after validation/authorization.

### Context Safety
- Every AI request carries `workspace_id`.
- Product retrieval for AI is always workspace-scoped.
- AI logs are tenant-scoped.

---

## 10) WhatsApp Architecture

Incoming flow:
Meta Webhook -> verify signature -> idempotency check -> map phone/account -> resolve workspace -> enqueue processing job.

Security:
- Signature verification
- Request authenticity checks
- Idempotency keys in `webhook_events`
- Replay protection

---

## 11) Payment Architecture

### Abstraction
- `PaymentGatewayInterface`
- Provider adapters (`ProviderA`, `ProviderB`, ...)
- `PaymentService` orchestrates domain-level flow

### Truth Source
- Payment status transitions are webhook-driven after provider verification.
- Frontend callback is never trusted as final payment proof.

### Core flow
Order -> payment link -> customer pays -> webhook verify -> idempotent process -> mark payment paid -> mark order payment status paid -> inventory updates -> notify workspace.

---

## 12) Queue / Events / Notifications

Queue candidates:
- incoming WhatsApp processing
- AI response generation
- payment webhook processing
- notification dispatch
- usage metering

Recommendations:
- Redis queue + cache + locks
- Workspace id included in payload for every async job/event

---

## 13) Storage Architecture

- Laravel storage abstraction with optional S3-compatible object storage.
- Object key convention:
  - `workspaces/{workspace_id}/products/...`
  - `workspaces/{workspace_id}/conversations/...`
- Server-side MIME/type/size validation required.
- Private visibility by default for sensitive files.

---

## 14) Logging / Monitoring

- Centralized app logs with workspace metadata.
- Webhook processing logs (`webhook_events`).
- Failed job monitoring.
- Payment and AI request tracing.
- Health checks and queue health dashboards.

---

## 15) Security Model

- Strong auth (hashed passwords, throttling, token expiry)
- Workspace context mandatory for tenant resources
- Policy + RBAC + membership enforcement
- CSRF for session flows; token auth for APIs
- Secrets only in backend env/secret manager
- Idempotency for webhooks and sensitive operations
- Audit logs for critical mutations
- No trust in frontend-supplied `workspace_id` without membership validation

---

## 16) Backup / DR Strategy

### Database
- Daily full backups + frequent incremental/WAL (or binlog-based).
- 30-90 day retention depending on compliance.

### Files
- Object storage versioning + cross-region replication (optional by tier).

### Restore
- Runbook-based staged restore:
  1) restore DB snapshot
  2) replay logs to point-in-time
  3) verify integrity checks
  4) recover storage objects

### DR Objective
- Define RPO/RTO per plan tier in operational policy.

---

## 17) Database ERD (Core - implemented foundation)

## Identity & Access
- `users`
  - PK: `id`
  - UQ: `email`, `phone`
- `platform_admins`
  - PK: `id`
  - UQ: `email`
- `auth_identities`
  - PK: `id`
  - FK: `user_id -> users.id (cascade)`
  - UQ: (`provider`, `provider_user_id`)

## Tenancy
- `workspaces`
  - PK: `id`
  - UQ: `uuid`, `slug`
  - FK: `owner_user_id -> users.id (nullOnDelete)`
  - IDX: (`type`, `status`)
- `workspace_users`
  - PK: `id`
  - FK: `workspace_id -> workspaces.id (cascade)`
  - FK: `user_id -> users.id (cascade)`
  - UQ: (`workspace_id`, `user_id`)
  - IDX: (`user_id`, `status`)

## Billing / Plans
- `plans`
  - PK: `id`
  - UQ: `code`
  - IDX: (`workspace_type`, `is_active`)
- `subscriptions`
  - PK: `id`
  - FK: `workspace_id -> workspaces.id (cascade)`
  - FK: `plan_id -> plans.id (restrict)`
  - UQ: `provider_subscription_id`
  - IDX: (`workspace_id`, `status`)
  - Soft deletes: yes

## Feature & Compliance
- `workspace_feature_flags`
  - PK: `id`
  - FK: `workspace_id -> workspaces.id (cascade)`
  - UQ: (`workspace_id`, `feature_key`)
- `audit_logs`
  - PK: `id`
  - FK: `workspace_id -> workspaces.id (nullOnDelete)`
  - FK: `user_id -> users.id (nullOnDelete)`
  - IDX: (`workspace_id`, `occurred_at`), (`entity_type`, `entity_id`)

## Security / Idempotency
- `webhook_events`
  - PK: `id`
  - FK: `workspace_id -> workspaces.id (nullOnDelete)`
  - UQ: (`provider`, `idempotency_key`)
  - UQ: (`provider`, `external_event_id`)
  - IDX: (`workspace_id`, `status`)

## API Tokens
- `personal_access_tokens`
  - PK: `id`
  - FK: `workspace_id -> workspaces.id (nullOnDelete)`
  - UQ: `token`

> Remaining commerce tables (products, inventory, orders, payments, conversations, messages, whatsapp_accounts...) are planned in next modules and will all include `workspace_id` where tenant-owned.

---

## 18) Proposed Laravel Folder Structure

```text
app/
  Actions/
  Http/
    Controllers/
      Api/
      Platform/
    Middleware/
    Requests/
    Resources/
  Jobs/
  Events/
  Listeners/
  Models/
    Concerns/
  Notifications/
  Policies/
  Services/
    Auth/
    Workspace/
    Product/
    Inventory/
    Customer/
    Order/
    Payment/
    WhatsApp/
    AI/
    Subscription/
    Audit/
    Feature/
  Support/
    Tenancy/
    Permissions/
config/
database/
  migrations/
  seeders/
docs/
  architecture/
routes/
  api.php
  platform.php
  web.php
tests/
  Feature/
  Unit/
```

---

## 19) Authentication Flows (detailed)

### Email Registration
Register -> validate -> create user -> create workspace by selected type -> attach owner role -> create default subscription -> issue Sanctum token with workspace_id.

### Phone OTP Login
Request OTP -> store hashed code with TTL + attempts -> verify OTP -> verify membership in selected workspace -> issue token.

### Google/Facebook
Receive provider access token -> fetch social profile -> find/create user via `auth_identities` -> resolve workspace -> issue token.

### Password Reset / Verification
- Built on Laravel password broker.
- Email verification via standard Laravel verification flow.
- Phone verification timestamp set on successful OTP verification.

### Platform Admin Login
`POST /platform/login` with separate guard/provider/model.

---

## 20) Workspace Resolution Flow

Authenticated User
-> `ResolveWorkspaceContext` (`{workspace}` route param or `X-Workspace-Id`)
-> verify active membership
-> set context singleton
-> set RBAC team id (`workspace_id`)
-> check subscription + feature gate
-> authorization policy/permission
-> execute query through scoped models

Cross-workspace request attempts fail with 403/404 and are covered by tests.

---

## 21) Testing Strategy

### Required suites
- Auth tests (email/password, OTP, social)
- Authorization tests (role and policy)
- Workspace isolation tests (cross-access denial)
- Feature access tests (type + plan + overrides)
- Inventory/order/payment/webhook idempotency tests
- AI/WhatsApp integration contract tests

### Isolation scenarios (must pass)
- Individual A cannot access Individual B resources
- Company A cannot access Company B resources
- Company cannot access individual private resources
- Individual cannot access company commerce resources

---

## 22) Implementation Roadmap (Module-by-Module)

1. Project Foundation ✅ (started)
2. Authentication ✅ (foundation started)
3. Users & Workspaces ✅ (foundation started)
4. Multi-Tenancy / Isolation ✅ (foundation started)
5. Platform Admin ✅ (foundation started)
6. Individual Dashboard (next)
7. Company/Store Dashboard
8. Products
9. Categories
10. Inventory
11. Customers
12. Orders
13. Conversations
14. WhatsApp
15. AI
16. Payment Gateway Abstraction
17. Payments
18. Subscriptions (advanced lifecycle)
19. Notifications
20. Audit Logs (expanded coverage)
21. Storage
22. Security Hardening & Test Expansion
23. Monitoring & Backup Automation
24. Deployment/Release Engineering

---

## 23) Current Implementation Status in Codebase

Implemented in this iteration:
- Laravel project bootstrap
- Sanctum + Socialite + Spatie Permission installation
- Core tenancy schema (`workspaces`, `workspace_users`, scoped tables)
- Plan/subscription foundation
- Platform admin separation (`platform_admins`, guard, routes, middleware)
- Workspace context middleware and scoped model base
- Auth foundations:
  - register (workspace type-aware)
  - password login
  - phone OTP flow
  - social token exchange
- Feature access service (type + plan + override)
- Seed foundation plans + permissions

Next iteration starts from module 6 onward (dashboards + commerce domains).
