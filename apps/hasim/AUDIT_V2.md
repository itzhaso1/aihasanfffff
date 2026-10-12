# Hasim Flutter V2 — Audit (pre-implementation)

**Branch:** `cursor/merchant-payments-plans-757c`  
**Rule:** Laravel = source of truth · Flutter = professional client · keep `/api/mobile/v1`

## Flutter V1 status
Solid scaffold (Riverpod, go_router, Dio, RTL/Cairo). Gaps: no splash branding assets, thin login, no forgot/Google, Hive opened but unused, polling-only realtime, Noop push, manual `email_account_id`, no AI UI, no plans/channels/profile edit, light theme only, hardcoded Arabic strings.

## Mobile API v1 — present
Auth login/logout/me · sessions · devices · workspaces · home/unread/search · conversations/messages/attachments · emails · customers · notifications · AI suggest/summarize.

## Gaps requiring additive Mobile APIs (non-breaking)
| Endpoint | Purpose |
|----------|---------|
| POST `/auth/forgot-password` | Password reset link (Password broker) |
| POST `/auth/reset-password` | Set new password with token |
| POST `/auth/social` | Google token → same Laravel user (SocialAuthService) |
| PATCH `/auth/profile` | Update name/email/phone/locale/timezone |
| PUT `/auth/password` | Change password (authenticated) |
| POST `/auth/avatar` | Upload avatar |
| GET `/emails/accounts` | Send-account picker |
| GET `/channels` | Connected vs available channels |
| GET `/plan` | Current entitlements snapshot |
| GET `/plans` | Official catalog comparison |
| GET `/branding` | Platform + workspace display branding |

## Existing but unused by Flutter
AI endpoints · search · mute/archive · notification preferences · customer show · attachment upload after message id.

## External / not fakeable
Google OAuth client IDs · FCM/APNs credentials · Reverb/Pusher · Meta WhatsApp Embedded Signup · HyperPay checkout.
