# P6 TikTok + Telegram deployment

Do **not** commit secrets. Configure via environment and platform admin UI.

## TikTok

| Item | Value |
| --- | --- |
| Developer portal | TikTok for Business / Marketing API apps |
| OAuth redirect | `TIKTOK_OAUTH_REDIRECT_URI` → `{APP_URL}/workspace/channels/tiktok/callback` |
| Webhook URL | `TIKTOK_WEBHOOK_CALLBACK_URL` or `{APP_URL}/tiktok-webhook` |
| Webhook subscription | POST `/open_api/v1.3/business/webhook/update/` with `event_type=DIRECT_MESSAGE` |
| Signature | `TikTok-Signature` header (HMAC-SHA256 with `TIKTOK_CLIENT_SECRET`) |
| Scopes | Set `TIKTOK_OAUTH_SCOPES` per TikTok Business Messaging authorization docs |

### Environment

```
TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=
TIKTOK_OAUTH_REDIRECT_URI=
TIKTOK_OAUTH_SCOPES=
TIKTOK_WEBHOOK_CALLBACK_URL=
```

### Production gates

- TikTok Business Messaging API access approval and regional availability
- Business account DM settings (“accept messages from everyone”) per TikTok prerequisites

## Telegram

| Item | Value |
| --- | --- |
| Setup | Create bot via BotFather; paste token in Workspace → Channels |
| Webhook | Registered automatically to `{APP_URL}/telegram-webhook/{publicWebhookKey}` |
| Secret | `secret_token` sent as `X-Telegram-Bot-Api-Secret-Token` (generated per connection) |
| Updates | `message`, `business_message`, `business_connection`, `edited_business_message` |

### Environment

Optional override:

```
TELEGRAM_API_BASE=https://api.telegram.org
```

### Staging vs production

- Use separate bots and `APP_URL` per environment
- Re-connect bot after changing `APP_URL` so `setWebhook` runs again

## Observability

P5 metrics/log events include `webhook.tiktok.*`, `webhook.telegram.*`, and `inbound.job_complete` with `provider=tiktok|telegram`.
