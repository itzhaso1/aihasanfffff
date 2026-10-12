# WhatsApp Webhook and Embedded Signup

This document reflects the current implemented code paths.

## 1) Final WhatsApp webhook route

- Route file: `routes/web.php`
- Endpoints:
  - `GET /whatsapp-webhook` (`webhooks.whatsapp.verify`)
  - `POST /whatsapp-webhook` (`webhooks.whatsapp.handle`)

Reason for using `web.php`: this route accepts external Meta requests and avoids common API-layer/WAF edge cases on some shared hosting setups where long verification query strings are blocked more aggressively on API paths.

Callback URL format to configure in Meta:

`https://your-domain.com/whatsapp-webhook`

## 2) CSRF configuration

In `bootstrap/app.php`, CSRF exceptions include:

- `whatsapp-webhook`

This is required because Meta does not send Laravel CSRF tokens.

## 3) Webhook verification flow (GET)

Controller: `App\Http\Controllers\Webhook\WhatsAppWebhookController::verify`

Expected query parameters:

- `hub.mode`
- `hub.verify_token`
- `hub.challenge`

Validation logic:

1. Check `hub.mode === subscribe`.
2. Compare `hub.verify_token` to `config('whatsapp.verify_token')`.
3. If valid, return `hub.challenge` with HTTP `200`.
4. If invalid, return `Forbidden` with HTTP `403`.

## 4) Webhook event ingestion flow (POST)

Controller: `App\Http\Controllers\Webhook\WhatsAppWebhookController::handle`

### Request validation

- Verifies `X-Hub-Signature-256` as `sha256=<hmac>` using request raw body and `config('whatsapp.app_secret')`.
- Invalid/missing signature returns HTTP `403` JSON:
  - `{"message":"Invalid signature"}`

### Processing path

1. Pass payload + headers to `App\Services\WhatsApp\WhatsAppService::processWebhook`.
2. Extract:
   - `entry[0].changes[0].value.metadata.phone_number_id`
   - first inbound message from `entry[0].changes[0].value.messages[0]`
3. Locate mapped phone number in `whats_app_phone_numbers`.
4. Create idempotent `webhook_events` row (`provider=whatsapp`, unique `external_event_id`).
5. Dispatch `ProcessIncomingWhatsAppMessage`.
6. Job writes/updates:
   - `customers`
   - `conversations` (channel = whatsapp)
   - `messages` (inbound)
   - conversation unread counters/last message metadata
7. Initial webhook response to Meta is HTTP `202`:
   - `{"received": true}`

Supported event path in current code: inbound message events under `messages[0]`. Other event classes (delivery/read status streams) are not processed in a dedicated handler yet.

## 5) Security notes (implemented)

- App secrets/tokens are server-side config/env only.
- Frontend does not contain Meta App Secret or permanent access tokens.
- Signature verification is required for POST webhook ingestion.
- Duplicate event processing is prevented using unique webhook event IDs.
- Invalid verification/signature requests are rejected with 403.

## 6) Environment variables used

```env
WHATSAPP_VERIFY_TOKEN=your_verify_token
WHATSAPP_APP_SECRET=your_whatsapp_app_secret
WHATSAPP_API_VERSION=v20.0
WHATSAPP_PERMANENT_TOKEN=your_whatsapp_permanent_token

META_APP_ID=your_app_id
META_APP_SECRET=your_app_secret
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=your_embedded_signup_config_id
WHATSAPP_EMBEDDED_SIGNUP_REDIRECT_URI=https://your-domain.com/workspace/channels
```

## 7) Meta Developer Dashboard setup

1. Open your Meta App and enable WhatsApp product.
2. Webhooks:
   - Callback URL: `https://your-domain.com/whatsapp-webhook`
   - Verify Token: same value as `WHATSAPP_VERIFY_TOKEN`
3. Subscribe at minimum to `messages` field.
4. Configure Embedded Signup and use its config ID in `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`.
5. Ensure allowed app domains / redirect URIs include your workspace channels page URL.

## 8) Troubleshooting

### 403 Forbidden

- Check callback URL path is exactly `/whatsapp-webhook`.
- Check WAF/firewall rules (request query/header blocking).
- Ensure `WHATSAPP_APP_SECRET` matches app used by Meta.

### Webhook verification failed

- Verify token mismatch is the most common issue.
- Ensure Meta is sending `hub.mode=subscribe`.
- Confirm the URL and method are GET.

### CSRF error

- Confirm `whatsapp-webhook` is in CSRF exception list.
- Clear cache: `php artisan optimize:clear`.

### POST events not received

- Validate HTTPS reachability from public internet.
- Check reverse proxy/firewall logs.
- Confirm Meta webhook subscriptions are active.

### Messages not visible in inbox

- Inspect payload for `metadata.phone_number_id` and `messages[0]`.
- Confirm that phone number exists in `whats_app_phone_numbers`.
- Check queue worker status for `ProcessIncomingWhatsAppMessage`.
- Inspect `webhook_events` row status.

### Environment variable issues

- Confirm all WhatsApp/Meta env vars are set.
- Run `php artisan config:clear` after changes.
