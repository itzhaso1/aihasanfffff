# WhatsApp

## Implemented

- Account + phone number models and workspace UI.
- Webhook verify/handle routes (`/whatsapp-webhook`) with throttle.
- Embedded signup / outbound service foundations (`WhatsAppOutboundService`, templates, outbound messages table).
- Feature gate: `whatsapp`.
- Usage meter: `whatsapp_messages`.

## Required to actually send

Set Meta/Cloud API credentials in `.env` (names vary by config — typically):

- `WHATSAPP_VERIFY_TOKEN`
- `WHATSAPP_APP_SECRET`
- Access tokens / phone number IDs stored on connected accounts

Without a valid token and Meta app webhook subscription, outbound sends will fail or stay queued.

## Honest status

**Token-gated.** UI and persistence exist; production messaging depends on operator-provided Meta credentials and webhook reachability (HTTPS).
