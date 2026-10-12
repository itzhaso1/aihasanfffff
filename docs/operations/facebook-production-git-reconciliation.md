# Facebook Messenger — repository status

**Status:** Facebook Messenger is implemented in this repository (webhook, inbound job, outbound, OAuth connect, profile resolution, unified outbound delivery).

## Core paths

| Path | Role |
| --- | --- |
| `app/Services/Meta/Facebook/FacebookMessagingEvent.php` | Parsed messaging DTO |
| `app/Services/Meta/Facebook/FacebookWebhookParser.php` | `page` payload parsing |
| `app/Services/Meta/Facebook/FacebookWebhookService.php` | Webhook dedup, workspace resolve, queue inbound job |
| `app/Services/Meta/Facebook/FacebookOutboundService.php` | Text outbound via Graph + delivery status |
| `app/Services/Meta/Facebook/FacebookConnectService.php` | Page OAuth connect |
| `app/Services/Meta/Facebook/FacebookProfileResolver.php` | Best-effort PSID profile name |
| `app/Jobs/ProcessIncomingFacebookMessage.php` | Persist message + AI dispatch |
| `app/Http/Controllers/Workspace/FacebookChannelConnectController.php` | Connect / callback / disconnect |

## Router

`MetaWebhookRouter` dispatches `FacebookWebhookService` when `object === 'page'`.

## Realtime

After a **new** inbound row is persisted (`wasRecentlyCreated`), `FacebookWebhookService` calls `InboxRealtimePublisher::publishNewInboundMessage`.

## Deploy checklist

1. Set `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, `META_FACEBOOK_OAUTH_SCOPES`, optional `META_FACEBOOK_OAUTH_REDIRECT_URI`.
2. Subscribe Meta app webhook to `messages` for the Page.
3. Run queue workers (inbound, realtime, ai, outbound, media) and Reverb on production host.
4. `php artisan test --filter=FacebookWebhook` after deploy config changes.
