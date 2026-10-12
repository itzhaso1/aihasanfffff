# SECURITY_ENDPOINT_MATRIX (Plan 2)

Generated from route + controller review. Prefixes: mobile `/api/mobile/v1`, finance `/api/finance/v1`, web workspace `/workspace/*`.

## Legend

| Column | Meaning |
|---|---|
| Auth | `public`, `sanctum`, `session`, `platform.admin` |
| Workspace | `required`, `resolved`, `none` |
| Tenant source | Where active workspace is chosen |
| Policy | Primary authorization gate |

## Mobile API (`routes/mobile.php`) — 78 endpoints

| Tier | Auth | Workspace | Tenant source | Notes |
|---|---|---|---|---|
| Auth (login/logout/me/profile) | sanctum / public | none / resolved on login | membership-validated `workspace_id` on login only | Session tokens user-scoped |
| Workspace data (65 routes) | sanctum | **required** (`workspace.resolve` + `workspace.member`) | `X-Workspace-Id` → resolver (membership enforced) | Body `workspace_id` does not bypass header |
| Attachments download | sanctum + signed URL | required + query `workspace` | signature binds attachment+workspace | `findOwned()` + policy |
| AI suggest/summarize | sanctum | required | conversation from body; scoped lookup + policy | Must match workspace context |
| Notifications | sanctum | required for list/mark-all; **mark-read now workspace-scoped** | `workspace_id` column filter | Fixed Plan 2 |

**Resource-ID routes (cross-tenant tested in `CrossWorkspaceIdorTest`):** emails, contacts, groups, campaigns, customers, catalog, conversations, AI conversation_id, notifications.

## Finance API (`routes/finance-api.php`)

| Route group | Auth | Workspace | Policy |
|---|---|---|---|
| Assistant ask | sanctum | `X-Workspace-Id` required | feature + membership; idempotency service |
| Bootstrap/catalog | sanctum | required | workspace-scoped models |
| Auth | public/sanctum | on login | AuthenticationService |

Gemini never receives workspace authority; orchestrators use `WorkspaceContext` only.

## Workspace Web (`routes/web.php` workspace group)

| Module | Auth | Permission split | Backend enforcement |
|---|---|---|---|
| Email inbox/send/accounts | session | `canUseEmail` vs `canManageEmailAccounts` | `EmailController::authorizeEmailUse/Manage` |
| Channels WhatsApp/Instagram | session | manager/owner for connect | `ChannelController` + policies; member → 403 |
| Finance invoices | session | finance permissions | policies + scoped models |
| Products/uploads | session | workspace membership | mixed SecureUpload coverage |

## Webhooks (no session)

| Provider | Signature | Tenant binding |
|---|---|---|
| WhatsApp/Meta | HMAC `X-Hub-Signature-256` | `phone_number_id` → single workspace |
| Payments | provider verifier | checkout_reference / order_number → single workspace |
| Subscriptions | provider verifier | billing attempt / checkout session |
| Resend | Svix HMAC | `EmailLog.provider_message_id` |

## Public endpoints

| Route | Auth | Tenant data | Controls |
|---|---|---|---|
| `POST /assistant/chat` | public | **none** (public plans snapshot only) | throttle 20/min IP; token caps; prohibited model override |
| `POST /menu/{slug}/ai-chat` | public | workspace from slug only | rate limits; no body workspace_id |
| Digital download | signed URL only | order item scoped | limit at issue time |

## Downloads

| Surface | Auth layers |
|---|---|
| Mobile message attachment | sanctum + signed + workspace query + policy |
| Mobile/web email attachment | sanctum/session + workspace match + safe path |
| Finance PDF | session + finance auth + scoped invoice |
| Platform merchant docs | signed + `platform.admin` |
