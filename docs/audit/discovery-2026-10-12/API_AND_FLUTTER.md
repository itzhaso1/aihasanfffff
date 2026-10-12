# API and Flutter — HASEM Discovery Audit

## 1. HTTP surfaces (registered in this tree)

| Surface | Prefix | Route file | Auth |
| --- | --- | --- | --- |
| Legacy API | `/api/*` | `routes/api.php` | Sanctum + workspace middleware on subsets |
| Mobile (Hasim) | `/api/mobile/v1/*` | `routes/mobile.php` via `api.php` L94–96 | Sanctum; `X-Workspace-Id` |
| Web workspace | `/workspace/*` (and related) | `routes/web.php` | Session + workspace middleware |
| Platform admin | `/platform/*` | `routes/platform.php` | `platform_admin` guard |
| Webhooks | Various paths | `web.php` + `api.php` | Signatures / tokens (per controller) |
| Broadcasting auth | `/api/broadcasting/auth` | `bootstrap/app.php` L40–42 | Sanctum |

### Not registered in this tree

| Surface | Documented prefix | Status |
| --- | --- | --- |
| Finance API | `/api/finance/v1` | **Missing** `routes/finance-api.php` |
| Cashier API | `/api/cashier/v1` | **Missing** `routes/cashier.php` |

**Evidence:** `glob **/finance-api.php` → 0; `routes/api.php` ends with mobile group only.

---

## 2. Mobile API v1 — major groups (from `routes/mobile.php`)

| Group | Controllers (namespace `Api\Mobile\V1`) | Notes |
| --- | --- | --- |
| Auth / profile | `AuthController`, sessions | Throttle `mobile-login` |
| Worker | `WorkerAccountController` | Engagements, inbox, sessions, earnings, disputes |
| Conversations | `ConversationController` | Messages, read state |
| Email | `EmailController`, contacts, campaigns | Feature-gated |
| Stories | `StoryController` | |
| Catalog | `ChatCatalogController`, settings | |
| Commerce | `CommerceCartController`, intelligence | Carts/orders path |
| AI | `AiSettingsController`, `AiEmployeeController`, `AiController` | Plan feature `ai` |
| Channels | `ChannelController`, `ChannelConnectController` | OAuth connect flows |
| Customers | `CustomerController` | |
| Home / unread | `HomeController`, `UnreadController` | |

Contract reference: `docs/mobile-api.md` (compare to code — **owner should diff**).

Middleware highlights:

- `workspace.resolve`, `workspace.member`, `workspace.feature:*`, `inbox.actor`, `mobile.idempotency` (on writes)

**Evidence:** `bootstrap/app.php` middleware aliases lines 70–78.

---

## 3. Legacy `/api` routes (`routes/api.php`)

- Auth register/login/OTP/social
- Workspace-scoped resources: customers, conversations, messages, payments, WhatsApp, subscriptions, AI (legacy controllers under `App\Http\Controllers\Api\`)
- Throttle profile `legacy-api`
- Payment/subscription webhooks at bottom of file

**Note:** Overlap with mobile v1 exists historically — mobile is the primary Hasim contract.

---

## 4. Flutter: `apps/hasim`

| Item | Value |
| --- | --- |
| Package name | `hasim` (`pubspec.yaml` line 1) |
| SDK | `^3.9.0` |
| State / routing | Riverpod, go_router |
| HTTP | Dio |
| Storage | flutter_secure_storage, Hive, Drift (local DB for offline features) |
| Push | firebase_messaging |
| Auth | google_sign_in + mobile auth API |

### Feature modules (lib/)

- `features/conversations/` — inbox, chat screen, attachments
- `features/email/` — mailbox UI
- `features/settings/` — AI settings, plans, channels, worker capabilities
- `features/worker/` — engagements, worker inbox
- `features/stories/`, search, privacy/PIN, workspace picker

Architecture notes: `apps/hasim/ARCHITECTURE.md`, design system `docs/HASIM_FLUTTER_DESIGN_SYSTEM.md`.

### API integration pattern

Repositories under `features/*/data/*_repository.dart` call Dio with bearer token and workspace header (pattern seen in `auth_repository.dart`, `plan_channel_repository.dart` — representative).

**Runtime verified:** No.

---

## 5. Flutter: `hasim_finance` & `hasim_cashier`

| App | In this tree | Documented API |
| --- | --- | --- |
| `apps/hasim_finance` | **Absent** | `/api/finance/v1` — no local tax/GL math per AGENTS.md |
| `apps/hasim_cashier` | **Absent** | `/api/cashier/v1`, offline catalog |

README and audit docs describe Windows runners and test counts — **cannot verify** without repo paths.

---

## 6. Web Blade parity (communication module)

- Inbox: workspace + worker share `workspace.conversations.index` view
- Chat catalog admin: full CRUD blades
- Commerce intelligence/admin blades under workspace commerce namespace

Hasim Flutter targets mobile API; web uses session routes (not identical JSON).

---

## 7. Missing / partial integrations (evidence-based)

| Integration | Status |
| --- | --- |
| Finance Flutter ↔ finance API | **Not in tree** |
| Cashier ↔ cashier API | **Not in tree** |
| Commerce order → payment link in app | Blocked server-side for commerce source |
| Instagram/Messenger live backend | Documented as partial / coming-soon in DOMAIN-CATALOG-SEPARATION |
| Gmail OAuth inbox | **Unknown** — email uses `EmailAccount` SMTP/IMAP services |

---

## 8. API versioning

- Mobile: explicit `v1` prefix
- Finance: documented `v1` — not mounted
- Legacy `/api` — unversioned resources

Backward compatibility rule in master spec — **policy**; not mechanically verified.

**No API routes or Flutter files were modified.**
