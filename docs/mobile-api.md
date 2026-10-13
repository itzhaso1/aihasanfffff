# Hasim Mobile API (v1)

REST API foundation for the **حاسم** mobile app.  
Base URL prefix: `/api/mobile/v1`  
Auth: Laravel Sanctum Bearer token  
Workspace: resolved from token `workspace_id` and/or header `X-Workspace-Id` (membership verified server-side — never trust client IDs alone).

Response envelope:

```json
{ "success": true, "data": {}, "meta": {}, "message": "..." }
```

Errors (Arabic user messages):

```json
{ "success": false, "message": "…", "errors": {} }
```

## Authentication

| Method | URL | Auth | Notes |
|--------|-----|------|-------|
| POST | `/auth/register` | public | Company signup: `name`, `email`, `password`, `password_confirmation`; optional `phone`, `workspace_name`, `device_name`, `device_type`. Creates the user + owner workspace (`type=company`) with the same rules as web `/register`, then returns the login envelope (`201`). Throttled `mobile-login`. Independent Worker signup is **not** this route. |
| POST | `/auth/login` | public | `email` / `phone` / `email_or_phone` + `password`; optional `workspace_id`, `device_name`, `device_type` |
| POST | `/auth/forgot-password` | public | `{email}` — Password broker reset link; Arabic JSON |
| POST | `/auth/reset-password` | public | `{email, token, password, password_confirmation}` |
| POST | `/auth/social` | public | `{provider: google\|facebook, access_token}`; optional `workspace_id`, `device_name`, `device_type` — same login envelope as password |
| POST | `/auth/logout` | Bearer | Revokes current token + linked push tokens |
| GET | `/auth/me` | Bearer | User + workspaces + current workspace |
| PATCH | `/auth/profile` | Bearer | `name`, optional `email`, `phone`, `locale`, `timezone` |
| PUT | `/auth/password` | Bearer | `current_password`, `password`, `password_confirmation` |
| POST | `/auth/avatar` | Bearer | multipart `avatar` image → public disk `avatar_path` |

## Device sessions

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/sessions` | List tokens/devices |
| DELETE | `/sessions/{tokenId}` | Revoke one (not current) |
| DELETE | `/sessions` | Revoke all others |

## Push devices

| Method | URL | Notes |
|--------|-----|-------|
| POST | `/devices` | Register FCM/APNs token (`provider`, `platform`, `token`) |
| DELETE | `/devices/{id}` | Revoke |

`FCM_ENABLED` must be true with `FCM_CREDENTIALS` / `FCM_CREDENTIALS_PATH` (HTTP v1) or `FCM_SERVER_KEY`. Inbound messages queue `SendPushNotificationJob` per company member device. Without credentials the job logs and skips (no fake success).

## Workspaces

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/workspaces` | Memberships |
| GET | `/workspaces/current` | Requires workspace context |
| POST | `/workspaces/switch` | Body: `workspace_id` — updates token workspace |

## Home / unread

| Method | URL |
|--------|-----|
| GET | `/home` |
| GET | `/unread` |
| GET | `/search?q=` |

## Conversations & messages

| Method | URL | Permission |
|--------|-----|------------|
| GET | `/conversations` | member | filters: `filter=all\|unread\|archived`, `channel`, `search`, `customer_priority`, `payment_status`, `review_status`; cursor pagination |
| GET | `/conversations/{id}` | view |
| GET | `/conversations/{id}/messages` | view | cursor pagination |
| POST | `/conversations/{id}/messages` | update | supports `Idempotency-Key` + `idempotency_key` |
| POST | `/conversations/{id}/read` | view |
| POST | `/conversations/{id}/archive` | update |
| POST | `/conversations/{id}/mute` | update |
| PATCH | `/conversations/{id}/classification` | update | `customer_priority` (`important`,`normal`,`not_important`), `payment_status` (`unpaid`,`paid`), `review_status` (`needs_review`,`no_review_needed`). Manual source wins over later automatic signals. |
| POST | `/messages/{id}/attachments` | update | multipart `file` |
| GET | `/attachments/{id}/download` | signed URL |

Channels are opaque strings from core (`whatsapp`, `web`, `manual`, …). App does not implement provider logic.

## Email

| Method | URL |
|--------|-----|
| GET | `/emails/accounts` | send-account picker (id, name, email, brand_color, logo_url — no secrets) |
| GET | `/emails/inbox` |
| GET | `/emails/sent` |
| GET | `/emails/drafts` |
| GET | `/emails/{id}` |
| POST | `/emails` | send via `WorkspaceEmailSender`; consumes `email_sends` (+1 per recipient) |
| POST | `/emails/{id}/read` |
| POST | `/emails/{id}/star` |

## Stories

Ephemeral workspace stories (24h default, max 168h). `ExpireStoriesJob` runs hourly.

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/stories` | Visible active stories for current user |
| POST | `/stories` | multipart: `type` text\|image\|video; text needs `body_text`; media needs `file` |
| GET | `/stories/{id}` | |
| POST | `/stories/{id}/view` | Mark viewed (increments once) |
| DELETE | `/stories/{id}` | Author only |
| GET | `/stories/{id}/viewers` | Author only |

Visibility: `workspace` \| `selected` (+ `selected_user_ids`) \| `hidden` (+ `hidden_user_ids`).

## Email contacts & groups

Address book separate from CRM customers.

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/contacts` | `q`/`search`, `favorite`, cursor |
| POST | `/contacts` | Duplicate `normalized_email` → 422 |
| GET | `/contacts/recent-recipients` | Parsed from outbound `EmailMessage.recipient` |
| GET/PATCH/DELETE | `/contacts/{id}` | |
| POST | `/contacts/{id}/favorite` | Toggle |
| GET/POST | `/contact-groups` | |
| PATCH/DELETE | `/contact-groups/{id}` | Delete detaches members only |
| POST | `/contact-groups/{id}/members` | Body: `contact_ids[]` sync |

## Email campaigns (bulk)

One `EmailMessage` per recipient (privacy). Asserts feature `email` + meter `email_sends` for recipient count before queue.

| Method | URL | Notes |
|--------|-----|-------|
| POST | `/email/campaigns` | `email_account_id`, `subject`, `body`, `contact_ids?`, `group_ids?`, `all_contacts?`, `emails?[]`, `confirm_all?` |
| GET | `/email/campaigns/{id}` | Status / counts |
| POST | `/email/campaigns/{id}/cancel` | Skips pending recipients |

Jobs: `ProcessEmailCampaignJob` → `SendCampaignRecipientJob` (consumes `email_sends` +1 on success).

## Channels / plan / branding

Workspace-context routes (Bearer + `X-Workspace-Id`).

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/channels` | Connected vs available channels; Arabic `status_label`; `can_connect_in_app` true only for WhatsApp when embedded signup is configured |
| GET | `/plan` | Current workspace `FeatureAccessService::entitlementsSnapshot` |
| GET | `/plans` | Official public catalog (starter/pro/business/enterprise) + `comparison_rows` / matrix |
| GET | `/branding` | Platform (حاسم / `#06C2A4`) + current workspace `{id,name,type}` |

## Customers

| Method | URL |
|--------|-----|
| GET | `/customers/{id}` |
| GET | `/customers/{id}/conversations` |

## Commerce carts and orders

Workspace-context Commerce endpoints require the dedicated `commerce.cart.*` or
`commerce.orders.*` permission and the `products` and `orders` workspace
features. They are staff-facing transaction APIs, not payment or delivery APIs.

| Method | URL | Notes |
|--------|-----|-------|
| POST | `/commerce/carts` | Optional `customer_id`, `currency`, `channel`, `expires_at`, `metadata` |
| GET | `/commerce/carts/{cart}` | Cart and server-resolved line snapshots |
| POST | `/commerce/carts/{cart}/items` | `chat_catalog_item_id`, optional variant ID, `quantity`, selected options |
| PATCH | `/commerce/carts/{cart}/items/{cartItem}` | Replace `quantity` |
| DELETE | `/commerce/carts/{cart}/items/{cartItem}` | Remove line |
| POST | `/commerce/carts/{cart}/clear` | Remove all lines |
| POST | `/commerce/carts/{cart}/validate` | Returns structured availability problems |
| POST | `/commerce/carts/{cart}/cancel` | Cancels an active cart |
| POST | `/commerce/carts/{cart}/order-preview` | Returns authoritative totals and validation result |
| POST | `/commerce/carts/{cart}/convert` | Creates an idempotent `pending_payment` order |
| GET | `/commerce/orders` | Commerce orders only |
| GET | `/commerce/orders/{order}` | Immutable line snapshots, seller summaries, reservation state |
| POST | `/commerce/orders/{order}/cancel` | Cancels an unpaid Commerce order |

`/convert` requires an `Idempotency-Key` header or `idempotency_key` body value
(8–128 characters). Cart item requests never accept seller or price fields:
the server resolves seller, price, currency, tax, stock, and digital
availability from the current Chat Catalog state. Responses never include
digital secrets, encrypted values, credentials, or private inventory paths.

Commerce conversion stops at `pending_payment`; it does not create payment
links, invoke HyperPay, confirm payment, deliver digital inventory, or book
services.

## Notifications

| Method | URL |
|--------|-----|
| GET | `/notifications` |
| POST | `/notifications/{id}/read` |
| POST | `/notifications/read-all` |
| GET/PUT | `/notification-preferences` |

## AI

Respects plan feature `ai`. Settings require `canManageAiSettings` (owner/admin/manager or `ai.manage`).

| Method | URL | Body |
|--------|-----|------|
| GET | `/ai/settings` | Workspace AI assistant config (`AiSetting`). Includes `name` and additive `assistant_name` (same stored value). `meta.ai_roles` + `meta.persona_catalog` list allowed enum values. |
| PUT | `/ai/settings` | Partial update: `name` or `assistant_name`, `ai_role`, `instructions`, `tone`, `rules`, structured `agent_persona`, … Empty assistant name resets to `AI Assistant`. `rules.memory_enabled` toggles inbox memory for the workspace. `business_information.experience` stores the assistant experience line. |
| POST | `/ai/probe` | Sends one short saved-settings prompt to the configured provider. Returns `connected`, `reply`, `model`, `elapsed_ms`, `tokens`, and six checks. Does not invent a successful connection when the provider call fails. |
| POST | `/ai/suggest-reply` | `conversation_id`, optional `content` |
| POST | `/ai/summarize-conversation` | `conversation_id` |

### AI employees

Generic AI employees (identity by `id`; `name` is display data only). Every route requires `canManageAiSettings` and resolves the employee inside the current workspace (other workspaces return 404). The current Inbox replies still use `/ai/settings`; employees become active in the reply path in a later migration.

| Method | URL | Body / notes |
|--------|-----|------|
| GET | `/ai/employees` | List, default first. `meta.persona_options` lists allowed languages, dialects, formality, tones, emoji and greeting policies, traits and response lengths. |
| POST | `/ai/employees` | Either `preset_key` (+ optional top-level overrides such as `name`) or a full employee: `name`, `role`, optional `job_title`, `avatar_key`, `gender`, `instructions`, `training_profile`, `experience_notes`, `persona{}`, `skills[{key, settings}]`, `tools[{name, allowed, max_calls_per_run}]`, `memory{}`, `model{provider, model, temperature, response_length, max_output_tokens, thinking_budget}`. The first active employee of a workspace becomes its default. `201`. |
| GET | `/ai/employees/{id}` | One employee with persona, skills, tools, memory and model settings. |
| PATCH | `/ai/employees/{id}` | Partial update in the same shape. Nested `persona`, `memory`, `model` merge per key; `skills` and `tools` replace when sent. Increments `config_version`. |
| DELETE | `/ai/employees/{id}` | Soft delete. The default employee cannot be deleted (`422`) until another default is set. |
| POST | `/ai/employees/{id}/default` | Makes the employee the single workspace default (active employees only). |
| POST | `/ai/employees/{id}/activate` | Activates; becomes default when the workspace has none. |
| POST | `/ai/employees/{id}/deactivate` | Deactivates and removes default status. |
| POST | `/ai/employees/{id}/test` | `message` (1–500). Preview run: nothing is sent or stored and no tool runs. Returns `status`, `text`, `deliverable` (always `false`), `decision`. Until the model gateway exists the status is `failed`. |
| GET | `/ai/employee-presets` | Starter templates (`key`, `version`, `display_name`, `avatar_key`, `summary`, `role`, `persona`, …). A preset is never an employee. |
| GET | `/ai/skills` | Canonical skill registry entries for employees: `key`, `name`, `description`, `category`, `version`, `enabled`, `supported_intents`, `supported_domains`, `allowed_tools`, `settings_validation`, `fingerprint`, `version_fingerprint`. |

## Commerce inventory items (read-only)

Chat Catalog as a product (settings, storefront, search, templates, digital inventory, and item writes) is removed. Remaining `GET /catalog` endpoints exist only so workers and commerce can read leftover `chat_catalog_items` rows. Auth: Sanctum Bearer + `X-Workspace-Id`. Requires workspace feature `products`. Permissions remain `chat.catalog.view`.

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/catalog` | Cursor list of leftover commerce items. Query: `q`, `item_kind`, `category_id`, `active_only`, `per_page`, `cursor` |
| GET | `/catalog/{item}` | `ChatCatalogItemResource` |

`search_products` for Inbox AI always returns an empty list. Do not invent products or prices.

Does not invent Meta/HyperPay behavior.

## Realtime

Broadcast driver configurable (`BROADCAST_CONNECTION`). Events:

- `message.created` → `private-workspace.{id}.conversations` and `…conversation.{id}`
- `conversation.updated` → same

Authorize in `routes/channels.php` (membership required).  
Production needs Redis + Reverb/Pusher/Ably — `log` driver is local-only.

Auth for private channels: Sanctum Bearer via `/api/broadcasting/auth` (Laravel broadcasting routes).

## Idempotency

Header `Idempotency-Key` on mutating mobile routes. Keys are scoped to `user + key + route`.

## Rate limits

Named limiters: `mobile-login`, `mobile-api`, `mobile-messages`, `mobile-email`, `mobile-ai`, `mobile-attachments`, `mobile-write`, `mobile-search`.

## Security notes

- Tenant isolation via `WorkspaceContext` + membership middleware + policies
- Private attachment disk; signed download URLs
- No mass-assignment of verification/plan fields from mobile
- Do not log tokens, passwords, or full sensitive message bodies in push/audit

## Flutter contract (minimal)

1. Login → store token + workspace  
2. Send `Authorization: Bearer` + `X-Workspace-Id`  
3. Subscribe private channels after broadcasting auth  
4. Register FCM/APNs via `/devices`  
5. Use cursor `meta.next_cursor` for lists  
6. Send `Idempotency-Key` UUID for message/email mutations  

Existing `/api/*` routes remain unchanged.
