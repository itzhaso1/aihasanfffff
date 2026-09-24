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

`FCM_ENABLED` must be true with credentials before real delivery. Job queues pushes; without credentials it logs and skips (no fake success).

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
| GET | `/conversations` | member | filters: `filter=all\|unread\|archived`, `channel`, `search`; cursor pagination |
| GET | `/conversations/{id}` | view |
| GET | `/conversations/{id}/messages` | view | cursor pagination |
| POST | `/conversations/{id}/messages` | update | supports `Idempotency-Key` + `idempotency_key` |
| POST | `/conversations/{id}/read` | view |
| POST | `/conversations/{id}/archive` | update |
| POST | `/conversations/{id}/mute` | update |
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
| GET | `/ai/settings` | Workspace AI assistant config (`AiSetting`). `meta.ai_roles` + `meta.persona_catalog` list allowed enum values. |
| PUT | `/ai/settings` | Partial update: `name`, `ai_role`, `instructions`, `tone`, `rules`, structured `agent_persona`, … |
| POST | `/ai/suggest-reply` | `conversation_id`, optional `content` |
| POST | `/ai/summarize-conversation` | `conversation_id` |

## Chat catalog settings

Requires workspace feature `products` and `chat.catalog.view` / `chat.catalog.manage` as implemented in controllers.

| Method | URL | Notes |
|--------|-----|-------|
| GET | `/catalog/settings` | Full `ChatCatalogResource` including SEO, search_weights, availability_schedule, customer_service_policy, communication profile |
| PATCH | `/catalog/settings` | `UpdateChatCatalogSettingsRequest` fields |
| PATCH | `/catalog/settings/communication` | Communication profile |
| POST | `/catalog/settings/publish\|draft\|pause\|archive` | Catalog lifecycle |

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
