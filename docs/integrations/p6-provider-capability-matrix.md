# P6 provider capability matrix

Sources: [TikTok Business Messaging API v1.3](https://business-api.tiktok.com/portal/docs/business-messaging-api/v1.3), [TikTok webhook verification](https://developers.tiktok.com/doc/webhooks-verification), [Telegram Bot API](https://core.telegram.org/bots/api). Cells marked **implemented** reflect this codebase; **official** reflects vendor docs; **sandbox** requires operator verification.

| Capability | WhatsApp | Facebook | Instagram | TikTok | Telegram |
| --- | --- | --- | --- | --- | --- |
| Text inbound | supported | partial UI | supported | official / implemented | official / implemented |
| Text outbound | supported | partial | supported | official / implemented | official / implemented |
| Image inbound | supported | unknown | supported | official / queued† | official / implemented |
| Image outbound | supported | unknown | partial | official‡ / not in driver | official / not in driver |
| Audio inbound | supported | unknown | limited | unknown | official / implemented |
| Audio outbound | supported | unknown | limited | unknown | official / not in driver |
| Video inbound | supported | unknown | limited | official / queued† | official / implemented |
| Video outbound | supported | unknown | limited | official‡ | official / not in driver |
| File inbound | supported | unknown | limited | unsupported types | official / implemented |
| File outbound | supported | unknown | limited | unknown | official / not in driver |
| Profile name | supported | partial | supported | username in webhook | first_name / username |
| Avatar | partial | unknown | partial | not in webhook | optional getUserProfilePhotos |
| Realtime | supported | n/a | supported | supported | supported |
| AI replies | supported | n/a | supported | supported | supported |
| Manual replies | supported | partial | supported | supported | supported |

† TikTok inbound media registers attachments; download via `/business/message/media/download/` is **not implemented** yet (attachments may fail gracefully).  
‡ TikTok outbound driver currently sends **TEXT** only via `/business/message/send/`.

## Eligibility / approval

| Provider | Requirement |
| --- | --- |
| TikTok | Business Messaging API **Open Beta** (regional); developer app + Business Messaging permission; business OAuth; data security/privacy review per TikTok docs |
| Telegram | Bot token via [@BotFather](https://core.telegram.org/bots#6-botfather); optional **business_message** updates when a business connects the bot |

## Integration model chosen

- **Telegram:** Bot API with `message` + `business_message` webhook updates (not MTProto client). Business replies use `business_connection_id` on outbound when present on the conversation.
- **TikTok:** Business Messaging OAuth (`open_id` as `business_id`) + app-level `DIRECT_MESSAGE` webhook with `TikTok-Signature` verification.
