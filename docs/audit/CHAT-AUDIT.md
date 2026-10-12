# CHAT-AUDIT

Audit date: 2026-09-13

---

## 1. What exists

| Channel | Backend | UI flag | Live send/receive |
| --- | --- | --- | --- |
| WhatsApp Cloud | `WhatsAppService`, `WhatsAppOutboundService`, webhook, `SendWhatsAppMessage`, `ProcessIncomingWhatsAppMessage` | Yes | Token-gated (`docs/product.md`, `docs/whatsapp.md`) |
| Email | accounts, inbox sync job, campaigns, Resend webhook | Yes | Depends on mailer / Resend secrets |
| Web / manual | `Conversation.channel` enum | Yes | In-app |
| Instagram | Channel cards `connected` from workspace settings | Yes | **No Graph Instagram service found** |
| Facebook Messenger | Same settings flags | Yes | **No Messenger ingest service found** |
| Telegram | — | No | Missing |
| TikTok | — | No | Missing |

Channel enum: `whatsapp,web,manual,instagram,facebook_messenger,email` (`StoreConversationRequest`).

Surfaces:

- Blade inbox: `resources/views/workspace/communication/inbox/*`
- Flutter: `apps/hasim` conversations, email, stories, contacts
- Mobile API: `routes/mobile.php`
- Core API: `/api/conversations`, `/api/messages`

---

## 2. Pipeline

```text
Inbound WhatsApp → WhatsAppWebhookController → ProcessIncomingWhatsAppMessage
  → Conversation/Message (workspace scoped)
Outbound → SendWhatsAppMessage job → Graph API
Email inbound → SyncEmailInboxJob / Resend webhook
Mobile send → ConversationController::storeMessage + throttle + idempotency
Local Flutter → Drift/Hive outbox (`outbox_worker.dart`, reconciler)
Realtime → events MessageCreated / ConversationUpdated (broadcast channels)
```

---

## 3. Findings

### AUD-CHAT-001
- Category: Chat
- Severity: High
- Title: Instagram / Messenger presented as channels without implementations
- Location: `ChannelController` (web + mobile), conversation filters
- Evidence: `connected` is a settings boolean; no Instagram/Telegram/TikTok services under `app/Services`
- Impact: Users believe omnichannel is live; messages silently do not route
- Recommendation: Mark “soon” or hide until webhooks exist
- Effort: S
- Priority: P1

### AUD-CHAT-002
- Category: Chat
- Severity: High
- Title: WhatsApp is not production-complete without secrets and verified webhooks
- Location: `config` WHATSAPP_*, `WhatsAppWebhookController`
- Evidence: product docs: “real send requires WHATSAPP_*”
- Impact: Lost customer messages / failed campaigns
- Recommendation: Health check “cannot send” in UI; fail closed
- Effort: M
- Priority: P1

### AUD-CHAT-003
- Category: Chat
- Severity: Medium
- Title: Duplicate / lost messages across offline outbox + server
- Location: `apps/hasim/lib/core/local_db/*`
- Evidence: local reconciler + server idempotency middleware on mobile group
- Impact: Double replies or missing history
- Recommendation: Idempotency keys end-to-end; tests for replay
- Effort: M
- Priority: P1

### AUD-CHAT-004
- Category: Chat
- Severity: Medium
- Title: Assignment / team inbox is lighter than users expect
- Location: `Conversation`, `ConversationUserState`
- Evidence: mute/archive/read exist on mobile; no full assignment service inventory
- Impact: Shared inbox collisions
- Recommendation: Explicit assignee + permissions tests
- Effort: M
- Priority: P2

### AUD-CHAT-005
- Category: Chat
- Severity: Medium
- Title: AI auto-reply can post into the same conversation
- Location: `AIService::generateReply`, `ProcessAIResponse`
- Impact: Prompt injection; leaking catalog; cost
- Recommendation: Confirm feature flag; never include other workspaces (query already filters workspace_id)
- Effort: M
- Priority: P1

---

## 4. Feature statuses

| Feature | Status |
| --- | --- |
| Conversations / messages | Complete (WhatsApp/email/manual) |
| Attachments | Partial (signed mobile download; SecureUpload should be used) |
| Search | Partial (mobile search + finance search are separate) |
| Customer profile beside chat | Partial (inbox customer-details views) |
| Notifications / push | Partial (`SendPushNotificationJob`, Flutter `push_service.dart`) |
| Stories | Implemented (expire job) |
| Email campaigns | Implemented (jobs) |
| Webhooks retry | Partial (queue failing log; UNKNOWN DLQ) |

UNKNOWN — Requires runtime verification: Meta signature, message ordering, media download, campaign bounce handling.
