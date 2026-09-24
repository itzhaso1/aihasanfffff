# PLAN 4 — Jobs + Queues + Webhooks + External Integrations Report

**Date:** 2026-09-18  
**Scope:** Jobs, queues, retry/backoff, webhooks, email/push/WhatsApp/payment/subscription integrations  
**Verdict:** **PASS WITH RUNTIME VERIFICATION**

---

## 1. BASELINE

HASEM uses Laravel queues (`ShouldQueue`) for async integration work. Critical paths:

- **Inbound:** Meta/WhatsApp webhooks → persist `WebhookEvent` → `ProcessIncomingWhatsAppMessage` → optional `ProcessAIResponse`
- **Outbound:** `SendWhatsAppMessage`, `SendEmailMessageJob`, `SendCampaignRecipientJob`, `SendPushNotificationJob`
- **Payments/subscriptions:** HTTP webhook controllers verify signature → queue `ProcessPaymentWebhook` / `ProcessSubscriptionWebhook`
- **Email platform:** Resend/Svix webhook synchronous in controller (verified + persisted)

Tenant restoration pattern: **`WorkspaceContext::runForId($workspaceId, ...)`** in virtually all workspace-scoped jobs.

---

## 2. JOB INVENTORY

| Job | Trigger | Workspace source | Side effect | Tries | Backoff | Unique | Idempotency |
| --- | --- | --- | --- | ---: | --- | --- | --- |
| `ProcessIncomingWhatsAppMessage` | WhatsApp webhook | ctor `workspaceId` | Persist message, dispatch AI, mark webhook processed | 3 | — | `whatsapp-inbound:{ws}:{eventId}` | DB message `external_message_id` |
| `ProcessAIResponse` | Inbound message / manual | ctor `workspaceId` (new) | AI reply message + optional WA outbound | 3 | 15/60/180s | `ai-reply:{ws}:{conv}:{msg}` | `metadata.in_reply_to_message_id` |
| `SendCampaignRecipientJob` | Campaign processor | ctor `workspaceId` | Send one campaign email | 3 | 60/300s | — | Recipient status + message reuse |
| `SendEmailMessageJob` | **Not dispatched in prod paths** | ctor `workspaceId` | SMTP send | 3 | — | — | Message ownership check |
| `SendPushNotificationJob` | Notifications | ctor `workspaceId` | FCM (not implemented) | 3 | 30/120/300s | — | Preference + membership gate |
| `SendWhatsAppMessage` | Outbound WA | outbound record | Graph API send | 3 | 30/120/300s | — | Outbound status machine |
| `SyncEmailInboxJob` | Scheduled/manual | ctor `workspaceId` | IMAP sync | 2 | — | — | Account ownership |
| `ProcessEmailCampaignJob` | Campaign start | ctor `workspaceId` | Queue recipient jobs | 3 | — | — | Campaign status gate |
| `ProcessPaymentWebhook` | Payment webhook | resolved in service | Settle payment | 5 | — | — | `WebhookEvent` dedup |
| `ProcessSubscriptionWebhook` | Subscription webhook | resolved in service | Billing lifecycle | 5 | — | — | Provider event ID |
| `CreatePaymentLink` | Async checkout | order workspace | Payment link | default | — | — | Plan 3 payment lock |
| `UpdateAIUsage` | Post-AI | workspace from usage | Metering | default | — | — | Usage record |
| `ExpireStoriesJob` | Scheduler | global | Story expiry | default | — | — | Time-based |

---

## 3. RETRY / BACKOFF MATRIX

| Workflow | Retryable errors | Non-retryable | Job behavior |
| --- | --- | --- | --- |
| Campaign email | timeout, connection refused, unknown | auth, TLS, provider rejected, config | **Fixed:** rethrow retryable; permanent → `failed` status |
| Individual email (`SendEmailMessageJob`) | all (rethrows) | — | `failed()` sets `delivery_status=failed` |
| WhatsApp outbound | 5xx, 429 | 4xx (marks failed, no throw) | `failed()` updates outbound |
| IMAP sync | wrapped mailbox errors | — | rethrows |
| Payment/subscription webhook | processing errors | invalid signature (HTTP layer) | queue retries |
| AI response | AI/transient | entitlement limits (logged, no throw) | DB idempotency + ShouldBeUnique |
| Push | would retry if send threw | not configured / not implemented | early return (no false success) |

---

## 4. IDEMPOTENCY

| Workflow | Mechanism | Duplicate side effect protected |
| --- | --- | --- |
| WhatsApp inbound event | `WebhookEvent::firstOrCreate` by `(workspace, provider, external_event_id)` | Yes — second webhook returns before job dispatch |
| WhatsApp message | `external_message_id` unique per workspace | Yes |
| AI reply | `ShouldBeUnique` + `existingReply()` metadata check | Yes (SQLite + Bus fake); **Redis runtime unverified** |
| Campaign recipient | `status=pending` gate + reuse `email_message_id` if already sent | Yes (new tests) |
| Payment webhook | `WebhookEvent` + payment state guards | Yes (existing tests) |
| Resend/Svix | Event persistence in service | Yes (signature + dedup in service) |

---

## 5. TENANT CONTEXT

All critical jobs accept explicit `workspaceId` (or resolve from owned row) and call `WorkspaceContext::runForId`.

**Verified by existing + new tests:**
- `JobIsolationExecutionTest` (email, WA, campaign, push)
- `EmailRetrySafetyTest`, `WebhookIdempotencyTest`

Jobs **fail closed** when `workspaceId` is null (email send, campaign recipient, push, sync).

---

## 6. WHATSAPP / META

### Flow (preserved)

```text
POST webhook → signature verify → MetaWebhookRouter → WhatsAppService::processWebhook
  → WebhookEvent firstOrCreate (dedup)
  → ProcessIncomingWhatsAppMessage (ShouldBeUnique)
  → storeIncomingMessage (external_message_id dedup)
  → ProcessAIResponse dispatch
  → WebhookEvent status=processed
```

### Tenant resolution

`phone_number_id` → `WhatsAppPhoneNumber` rows → **exactly one workspace** or fail closed (log + return).

### NF-04

Processed flag set **after** persist + AI dispatch (unchanged, correct).

### Tests

- `WebhookIdempotencyTest`
- `ProductionHardeningRemediationTest` (existing)

---

## 7. PAYMENT WEBHOOKS

| Step | Implementation |
| --- | --- |
| Signature | Provider `verifyWebhook`; unverified → 401, no job |
| Tenant | `PaymentService::resolveTrustedWebhookBinding` — no bare order_number guessing |
| Dedup | `WebhookEvent::firstOrCreate` |
| Queue | `ProcessPaymentWebhook` (5 tries) |
| Financial effect | Plan 3 — idempotent settlement |

Existing: `WebhookReplayProtectionTest`, `PaymentWebhookTenancyTest`, `WebhookFailClosedSecretTest`.

---

## 8. SUBSCRIPTION WEBHOOKS

`ProcessSubscriptionWebhook` → `SubscriptionWebhookProcessor` with provider event ID tracking and billing attempt guards (Plan 1).

Out-of-order / late events: state machine guards in subscription services — **not modified** (no invented transitions).

---

## 9. EMAIL

### H-08 — FIXED

**Changes:**
- `CustomerMailboxErrorClassifier::isRetryable()` — transient vs permanent
- `EmailCampaignService::sendRecipient()` — keeps recipient `pending` on transient failure; reuses existing `EmailMessage` on retry; syncs from `sent` message if provider succeeded but ack lost
- `SendCampaignRecipientJob` — rethrows retryable errors; `$tries=3`, backoff `[60, 300]`

**Tests:** `EmailRetrySafetyTest` (3 cases)

### H-10 — NEEDS PRODUCT DECISION

`SendEmailMessageJob` is implemented with tenant checks and `failed()` handler but **never dispatched** from production send paths (mobile/web remain synchronous via `WorkspaceEmailSender`).

| Option | Risk |
| --- | --- |
| Wire job for outbound sends | Changes API latency/timeout contracts; needs idempotency UX |
| Keep synchronous | Current behavior; job remains for isolation tests only |
| Remove job | Breaking change for tests |

**Recommendation:** Keep synchronous until product spec defines async email contract.

---

## 10. FCM / PUSH

### H-09 — PRODUCT / PROVIDER DECISION REQUIRED

- `SendPushNotificationJob` has retry/backoff (NF-13)
- **No FCM HTTP client implemented**
- **Fixed:** When FCM appears configured, job now logs **warning** (`FCM HTTP client is not implemented`) instead of implying dispatch success

Implementing FCM requires: server key/service account spec, token invalidation policy, payload schema.

---

## 11. RESEND / SVIX

`ResendWebhookController`:
- Requires configured webhook secret (fail closed)
- Svix signature verification with tolerance
- Persists via `ResendWebhookService`
- Headers redacted in logs (existing pattern)

No queue — synchronous processing acceptable for delivery status webhooks.

---

## 12. FAILURE RECOVERY

| Scenario | Behavior |
| --- | --- |
| WhatsApp persist fails | Job retries; webhook stays pending until processed |
| AI dispatch fails after persist | Webhook not marked processed → provider retry safe; message dedup prevents duplicate rows |
| Campaign transient SMTP | Recipient stays pending; queue retries |
| Campaign permanent reject | Recipient `failed`; no infinite retry |
| Push FCM missing | Skip with explicit log (no false sent) |
| Payment webhook job fails | Queue retry; settlement idempotent |

---

## 13. OBSERVABILITY

Jobs log safe metadata (workspace_id, recipient/message ids, categories). Secrets redacted via `WebhookHeaderSanitizer` and mailbox error classifier.

---

## 14. TEST RESULTS

### Jobs / Integration (targeted)

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| `tests/Feature/Feature/Integrations/*` | 9 | 0 | 0 | 0 |

Includes: `EmailRetrySafetyTest`, `AIJobUniquenessTest`, `WebhookIdempotencyTest`, `JobRetryPolicyTest`

### Webhooks (included in critical + integration)

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| Integration webhook tests | 2 | 0 | 0 | 0 |
| Existing replay/tenancy tests | (in critical suite) | — | — | — |

### Critical Laravel (SQLite)

| | Passed | Failed | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| `phpunit.critical.xml` | 421 | 0 | 0 | 2 |

---

## 15. FINDINGS UPDATED

| ID | Status | Summary |
| --- | --- | --- |
| **H-07** | **PARTIALLY_FIXED** | Workspace-scoped unique key; DB idempotency; Bus fake collapses duplicates. **Redis queue uniqueness not verified locally.** |
| **H-08** | **FIXED** | Transient campaign failures retry; permanent failures mark failed; message reuse prevents double-send |
| **H-09** | **OPEN** | FCM HTTP not implemented — **PRODUCT DECISION REQUIRED** |
| **H-10** | **DOCUMENTED** | `SendEmailMessageJob` unused in prod paths — **NEEDS PRODUCT DECISION** for async |
| **NF-04** | **FIXED** | WhatsApp processed-after-dispatch order preserved |
| **NF-13** | **FIXED** | Push job retry/backoff configured; delivery still stub |

### Code changes (Plan 4)

- `CustomerMailboxErrorClassifier::isRetryable()`
- `EmailCampaignService::sendRecipient()` — retry-safe campaign sends
- `SendCampaignRecipientJob` — rethrow transient
- `ProcessAIResponse` — workspace in unique key + backoff; dispatch sites updated
- `SendPushNotificationJob` — no false success when FCM configured
- Tests under `tests/Feature/Feature/Integrations/`

---

## 16. RUNTIME VERIFICATION

| Item | Required | Local status |
| --- | --- | --- |
| **Redis** + queue worker | `ShouldBeUnique` under concurrent workers (H-07) | **NOT AVAILABLE** — phpredis extension not loaded |
| **FCM** credentials + HTTP | Real push delivery (H-09) | **NOT TESTED** — not implemented |
| **Live provider webhooks** | Stripe/HyperPay/Meta end-to-end | Manual / staging only |
| **Production webhook secrets** | Resend, payment, Meta | Not accessed in this plan |

---

## 17. REMAINING RISKS

1. **H-07:** Unique job locks on Redis/cache store not verified in this environment.
2. **H-09:** Push appears configurable but does not deliver.
3. **H-10:** Synchronous email remains; long SMTP holds HTTP workers.
4. **Finance API idempotency:** Out of Plan 4 scope (Plan 3 NF-09).
5. **Instagram Meta webhooks:** Logged/diagnostic only — not fully routed.

---

## 18. PLAN 4 VERDICT

### **PASS WITH RUNTIME VERIFICATION**

**Met in code + SQLite tests:**
- Campaign email retry/idempotency (H-08)
- WhatsApp webhook dedup + tenant fail-closed
- AI reply DB idempotency + workspace-scoped unique keys
- Push no longer logs false success when FCM configured
- Job tenant isolation (existing + new tests)

**Requires production/staging verification:**
- Redis-backed `ShouldBeUnique` under concurrent workers (H-07)
- FCM implementation decision (H-09)
- Async email product decision (H-10)
