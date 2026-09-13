# P0-7 FINAL

**Date:** 2026-09-13  
**P0-1 / P0-2 / P0-3 / P0-4 / P0-5 / P0-6:** CLOSED — not reopened.  
**Scope:** Inbound webhook authentication, missing/empty secret fail-closed, verify-before-write, replay. No finance/POS/tenancy/Redis/backup/UI work.

## Status

**CLOSED**

Every security-sensitive inbound webhook was inventoried and exercised on SQLite and isolated MariaDB. Missing, empty, whitespace, invalid, and unsigned requests return **401/403**, dispatch **no** business job, and write **no** payment, subscription, WhatsApp, or email business rows. Valid signed requests keep existing behavior. Production database `hasem` was not used.

P0-8 was not started.

---

## Webhook Inventory

| Provider | Route | Auth | Secret/config | Replay | Missing credential | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Local payment | `POST /api/webhooks/payments/local` | HMAC-SHA256 `timestamp.rawBody` (`X-Webhook-Timestamp` + `X-Webhook-Signature`) | `payment.providers.local.webhook_secret` / `LOCAL_PAYMENT_WEBHOOK_SECRET` | `webhook_events` unique `(provider, external_event_id)` | **401**, no job, no mutation | **PROVEN** |
| Stripe payment | `POST /api/webhooks/payments/stripe` | Stripe `Stripe-Signature` `t=` / `v1=` HMAC | `STRIPE_WEBHOOK_SECRET` | same `webhook_events` | **401**, no job, no mutation | **PROVEN** |
| Local subscription | `POST /api/webhooks/subscriptions/local` | HMAC-SHA256 `timestamp.rawBody` | `SUBSCRIPTION_WEBHOOK_SECRET` | `webhook_events` `subscription:{provider}` | **401**, no job, no activation | **PROVEN** |
| HyperPay subscription | `POST /api/webhooks/subscriptions/hyperpay` | None implemented — official contract **not in repo** | n/a | n/a | **401**, never trusted | **FAIL-CLOSED / contract NOT PROVEN** |
| Other subscription `{provider}` | same path | `UnconfiguredSubscriptionBillingProvider` | n/a | n/a | **401** | **PROVEN reject** |
| WhatsApp verify | `GET /whatsapp-webhook` | `hub.verify_token` === `WHATSAPP_VERIFY_TOKEN` (`hash_equals`) | `whatsapp.verify_token` | n/a (challenge) | **403** | **PROVEN** |
| WhatsApp inbound | `POST /whatsapp-webhook` | `X-Hub-Signature-256` = `sha256=` HMAC of raw body | `WHATSAPP_APP_SECRET` | `webhook_events` `whatsapp` + `entry[0].id` | **403**, no process | **PROVEN** |
| Resend / Svix | `POST /webhooks/resend` | Svix `svix-id` + `svix-timestamp` + `svix-signature` (`whsec_` key) | `RESEND_WEBHOOK_SECRET` | `email_webhook_events.payload.svix_id` | **401**, no `EmailLog` update | **PROVEN** |
| Unknown payment provider | `POST /api/webhooks/payments/{other}` | allowlist `local\|stripe` only | — | — | **401**, no job | **PROVEN** |

Slack `LOG_SLACK_WEBHOOK_URL` is **outbound** logging, not an inbound webhook.

### CSRF exceptions (`bootstrap/app.php`)

| Path | Why exempt | Real auth |
| --- | --- | --- |
| `webhooks/resend` | Svix does not send Laravel CSRF | Svix signature **before** handle |
| `whatsapp-webhook` | Meta does not send Laravel CSRF | verify token (GET) / HMAC (POST) |

Payment and subscription live under `/api` (no CSRF). CSRF exemption is **not** treated as authentication.

---

## Findings Before Fix

| Hole | Effect |
| --- | --- |
| Payment / subscription controllers always `202` + `dispatch` **before** verify | Missing secret still enqueued a trusted-looking job and returned success semantics |
| `LocalPaymentGateway` unverified result still included `reference` | Missing/invalid local secret could `firstOrCreate` a `WebhookEvent` (`invalid`) after tenant bind |
| `SubscriptionWebhookProcessor` looked up checkout **then** wrote `WebhookEvent` for unverified events | Payload `provider_checkout_id` caused a DB row on rejected auth |
| WhatsApp GET used `$token === config(...)` | `null === null` accepted Meta subscribe when `WHATSAPP_VERIFY_TOKEN` unset |
| Whitespace secrets treated as configured | `"   "` could HMAC-verify |
| Resend replay created a new `EmailWebhookEvent` every time | Duplicate delivery re-applied log status |
| `.env.example` empty values were OK but not labeled fail-closed | Operator could treat empty as “dev unsigned mode” |

Verifiers already rejected empty string in several gateways; HTTP and write paths did not enforce **NO AUTH = NO TRUST**.

---

## Changes

- `WebhookCredential::configured()` — trim; null/empty/whitespace = unconfigured
- Payment + subscription controllers **verify first**; reject **401**; dispatch only if verified
- Payment provider allowlist: `local`, `stripe`
- `PaymentService::processWebhook` returns immediately if `verified !== true` (no bind, no `WebhookEvent`)
- `SubscriptionWebhookProcessor` returns immediately if unverified (no checkout lookup, no event, no activation)
- WhatsApp GET: configured token required; `hash_equals`; missing/empty/whitespace → **403**
- WhatsApp POST: whitespace secret → invalid
- Resend: whitespace secret rejected; replay by `svix_id` does not re-mutate
- `.env.example`: empty placeholders + fail-closed comments (no `changeme` / `password` defaults)
- Rejected HTTP body is generic (`Webhook authentication failed.`); reasons go to logs **without** secret values

No payment settle / POS / tax / tenancy architecture change.

---

## Missing-Secret Tests

HTTP evidence in `WebhookFailClosedSecretTest`:

- Payment `null` / `''` / `'   '` → **401**, `Bus` empty, payment/order stay pending, `webhook_events` = 0
- Subscription same → **401**, checkout pending, no active subscription
- WhatsApp GET missing/empty token → **403** (no challenge echo)
- WhatsApp POST missing/whitespace secret → **403**, no message
- Resend missing/empty/whitespace → **401**, `EmailLog` stays pending

---

## Invalid-Signature Tests

- Payment wrong HMAC + malformed timestamp/signature → **401**, zero mutations
- Unsigned `POST /api/webhooks/payments/local` → **401**
- Subscription wrong HMAC → **401**
- WhatsApp POST without / wrong `X-Hub-Signature-256` → **403**
- Resend wrong Svix key → **401**
- HyperPay JSON POST → **401**, checkout not paid

---

## Replay Tests

- Payment same signed `event_id` twice → one `webhook_events` row, order paid once (`OrderPaymentFlow` + P0-7)
- Stripe replay via `PaymentWebhookSecurityTest`
- Subscription same `event_id` → one active subscription
- WhatsApp same `entry.id` → one message
- Resend same `svix-id` → one `email_webhook_events` row, log `sent` once

---

## Tenant Binding

Unchanged P0-1 fail-closed (not reopened):

- Valid local payment + bound reference → workspace A only
- Unbound / ambiguous reference → no write (HTTP **202** after auth, processor no-ops)
- WhatsApp unknown `phone_number_id` → no write
- `phone_number_id` is **globally unique** in schema; two-workspace bind cannot be persisted
- Subscription unknown `provider_checkout_id` → no activation

---

## Secret Leak Check

Rejected payment response does not contain the configured secret or an `Authorization` header. Log reasons are codes/messages only (`webhook_secret_missing`, `unverified`, …), not secret material. Raw bodies are not logged by these controllers.

---

## SQLite

```bash
php artisan test --testdox --filter="WebhookReplay|PaymentWebhook|SubscriptionSecurity|SubscriptionLifecycle|PaymentWebhookTenancy|FailClosedWorkspaceTenancy|WorkspaceIsolation|WebhookFailClosedSecret|ResendWebhook|WhatsAppWebhookRoute|WebhookVerification|OrderPaymentFlow"
```

PASS: **80**  
FAIL: **0**  
SKIPPED: **0**  
ERRORS: **0**  
Assertions: **270**

P0-7 file alone: **16** tests, **84** assertions.

---

## MariaDB/MySQL

`php tests/bin/mysql-p0-probe.php` → `status=READY`, `hasem_p0_tenancy_test`  
Engine: MariaDB **10.4.32**  
App `.env` database `hasem` **not** used (`phpunit.mysql.xml` → `DB_DATABASE=hasem_p0_tenancy_test`)

Same filter via `php vendor/bin/phpunit -c phpunit.mysql.xml --testdox --filter="..."`

PASS: **80**  
FAIL: **0**  
SKIPPED: **0**  
ERRORS: **0**  
Assertions: **270**

---

## Remaining Review

- **HyperPay subscription webhook contract** is not specified in-repo. Endpoint is fail-closed (never verified). When an official signature spec exists, implement that verifier — do not invent HMAC.
- **`PaymentGatewayManager`** still maps unknown names to the local gateway for *link creation*. HTTP webhook path now allowlists `local|stripe` only.
- WhatsApp non-message webhooks (delivery/read) are still unused; they would still require the same HMAC first.
- Operator “secret missing in production” is log + HTTP 401, not a boot-time exception (avoids taking the whole app down).

P0-8 not started.
