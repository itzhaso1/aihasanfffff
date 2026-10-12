# Hasim Chat — Native Channel Connection

**Date:** 2026-10-05  
**Scope:** Connect and manage WhatsApp, Meta (Facebook Messenger + Instagram), Telegram, and TikTok from `apps/hasim` without opening the Hasim web dashboard for the normal path.  
**Not in this phase:** Subscription, Tax, Commerce, Product Templates, Sellers, Email attachments, Worker Push, AI, Stories, Inbox.

Independent Worker remains outside workspace membership. Channel list and connect/disconnect sit behind `workspace.member` plus `canManageChannels` (owner / admin / manager or `whatsapp.manage`) and the `whatsapp` feature flag. A worker cannot manage company channels; hiding UI is not the security boundary.

Laravel owns OAuth secrets, authorization codes, access tokens, refresh tokens, and Telegram bot tokens after submit. Flutter starts the flow, opens the system browser when required, then refreshes status from `GET /channels`.

Verification split:

| Layer | Result |
| --- | --- |
| Code verified | Yes. Mobile contract, callbacks, Flutter UI, Android/iOS custom scheme. |
| Integration verified | Yes. Laravel HTTP tests with `Http::fake`. Flutter widget/controller tests with fakes. |
| Real provider verified | **No.** This environment did not complete live Meta / TikTok / Telegram provider OAuth. |

---

## Mobile contract

All native routes are Sanctum + `X-Workspace-Id` + `workspace.member`.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/mobile/v1/channels` | Status flags. No secrets. |
| POST | `/api/mobile/v1/channels/{channel}/connect` | Start connect. `{channel}` = `whatsapp\|facebook_messenger\|instagram\|tiktok\|telegram` |
| POST | `/api/mobile/v1/channels/{channel}/disconnect` | Disconnect the same keys |
| GET | `/api/mobile/v1/channels/facebook_messenger/pages?attempt=` | Page names/ids only after multi-page Facebook OAuth |
| POST | `/api/mobile/v1/channels/facebook_messenger/pages` | Finish Facebook with `attempt` + `page_id` |

Start response (OAuth / Embedded Signup): `channel`, `mode`, `authorization_url`, `callback_scheme` (`hasim`), `expires_at`.  
Telegram start with `bot_token`: `mode=bot_token`, `connected=true`, display name only.

`manage_url` remains on the list payload. Flutter uses it only when `can_connect_in_app` is false (missing provider config, or Email `web_only`).

Return URL: `hasim://channels/oauth?channel=&status=&message=&workspace=` (`status` = `success` \| `error` \| `select_page` \| `cancelled`). Flutter never receives an authorization code or provider secret.

---

### WhatsApp

- **Current Web flow:** Feature-flagged `POST workspace/channels/whatsapp/connect`. Meta Embedded Signup JS SDK posts a code to Laravel. Laravel exchanges it with `META_APP_SECRET` and stores `WhatsAppAccount` + phone numbers. Not a redirect OAuth loop.
- **Mobile contract:** `POST /channels/whatsapp/connect` issues a one-time encrypted attempt token and returns a Laravel-hosted URL: `/mobile/channel-connect/whatsapp/{token}`. Flutter opens that URL in the **system browser**. The hosted page loads the Facebook JS SDK with `meta_app_id` + `embedded_signup_config_id` only. Completing posts `{code, session_info}` to Laravel. Laravel consumes the token, exchanges the code, then returns `{redirect: hasim://channels/oauth?...}`.
- **Flutter flow:** Settings → Channels → ربط. System browser. On resume / deep link, refresh list. Status pill: متصل / غير متصل. إعادة ربط and قطع الاتصال when allowed.
- **Callback:** Attempt token is bound to `user_id` + `workspace_id`, TTL 15 minutes, consume-once (`410` on replay). CSRF is excepted for this POST because the attempt token is the credential. Membership is re-checked before connect.
- **Status:** `connected` when an account is `connected` and has phone numbers; otherwise `needs_setup` or `disconnected`.
- **Disconnect:** `POST /channels/whatsapp/disconnect` marks workspace WhatsApp accounts and numbers disconnected. Reconnect is another Embedded Signup start.
- **Verification:** Laravel start/complete/replay/disconnect tests. Flutter start + callback success. Hosted page asserts app id/config id and **does not** render `meta_app_secret`.
- **Blockers:** Real Meta Embedded Signup not live-tested. Requires `whatsapp.meta_app_id` + `whatsapp.embedded_signup_config_id`. This is not a Flutter WebView of the Hasim dashboard; Meta’s JS SDK still needs a hosted HTTPS page.

### Meta

Meta in this product is two channels: **Facebook Messenger** and **Instagram**. They are not the same OAuth app flow.

#### Facebook Messenger

- **Current Web flow:** `GET workspace/channels/facebook/connect` → Facebook dialog OAuth → `GET workspace/channels/facebook/callback`. Laravel exchanges the code, loads Pages, persists one page token (encrypted). Multiple pages previously could not finish on mobile.
- **Mobile contract:** `POST /channels/facebook_messenger/connect` with `return_to=mobile`. Same Laravel callback. Single page: redirect `hasim://...&status=success`. Multiple pages: Laravel caches page tokens server-side and redirects `status=select_page&attempt=`. Flutter then `GET pages` (id + name only) and `POST pages` with `page_id`.
- **Flutter flow:** System browser. Multi-page bottom sheet: اختر صفحة Facebook. No tokens in the sheet.
- **Callback:** `MetaOAuthState` encrypted payload: `user_id`, `workspace_id`, `purpose=facebook_connect`, `nonce` (consume-once), `return_to`. Mobile callback does **not** require a web session; the state identifies the initiator. Web `return_to=web` still requires the session user to match.
- **Status / Disconnect:** `WorkspaceMetaConnection` kind `facebook_page`, status `connected`. Disconnect sets `disconnected`. Reconnect starts OAuth again.
- **Verification:** Single-page callback, multi-page selection, cross-workspace attempt rejected (`422`), secrets absent from mobile JSON, disconnect. Flutter starts Facebook in the system browser.
- **Blockers:** Real Facebook OAuth not live-tested. Requires `meta.app_id` and `meta.facebook_oauth_scopes`.

#### Instagram

- **Current Web flow:** `GET workspace/channels/instagram/connect` → Instagram Business Login → `GET workspace/channels/instagram.callback`. Laravel exchanges code for a long-lived user token (encrypted). Duplicate Instagram user id cannot bind to another workspace.
- **Mobile contract:** Same begin URL with `return_to=mobile`. Flutter never sees the code.
- **Flutter flow:** System browser → `hasim://channels/oauth` → refresh.
- **Callback / Status / Disconnect:** Same state machine as Facebook, purpose `instagram_connect`. Disconnect sets connection `disconnected` and workspace settings `channels.instagram.connected=false`. Reconnect `updateOrCreate` on the same workspace.
- **Verification:** Sessionless mobile callback, invalid state, web-session user mismatch rejected, replay rejected, duplicate workspace bind rejected, disconnect + reconnect.
- **Blockers:** Real Instagram OAuth not live-tested. Requires `meta.app_id` and `meta.instagram_oauth_scopes`.

### Telegram

- **Current Web flow:** Not OAuth. `POST workspace/channels/telegram/connect` with BotFather token. Laravel calls `getMe`, stores encrypted token, `setWebhook`.
- **Mobile contract:** `POST /channels/telegram/connect` with `bot_token`. Response is connected state + `@username`. Token is not echoed. Duplicate `bot_user_id` on another workspace is rejected.
- **Flutter flow:** Bottom sheet asks for the token. It is POSTed once and not kept in app storage. No browser.
- **Callback:** None.
- **Status:** `WorkspaceTelegramConnection` status connected; hint shows `@bot`.
- **Disconnect:** Deletes webhook when possible, then status `disconnected`. Reconnect is another token POST.
- **Verification:** Connect without echo, disconnect, Flutter sheet + connect without browser.
- **Blockers:** Real Telegram Bot API not live-tested. Token must be typed in the app (same as web). That is the provider contract, not a missing OAuth port.

### TikTok

- **Current Web flow:** `GET workspace/channels/tiktok/connect` → TikTok authorize URL (`client_key`, scopes, state) → `GET workspace/channels/tiktok/callback`. Laravel exchanges code with `client_secret`, stores encrypted access + refresh tokens. Same `open_id` cannot bind to another workspace.
- **Mobile contract:** Begin with `return_to=mobile`. Flutter opens `authorization_url` only.
- **Flutter flow:** System browser → Laravel callback → `hasim://channels/oauth` → refresh.
- **Callback:** `MetaOAuthState` purpose `tiktok_connect` (shared signed-state helper, not a Meta Graph call).
- **Status / Disconnect:** `STATUS_CONNECTED` vs `STATUS_REVOKED`. List treats revoked as disconnected. Reconnect is a new OAuth start.
- **Verification:** Start URL has no `tt-secret`. Callback persists tokens encrypted (ciphertext not in `toArray`). Disconnect. Flutter starts TikTok in the system browser.
- **Blockers:** Real TikTok OAuth not live-tested. Requires `tiktok.client_key`, `tiktok.redirect_uri`, `tiktok.oauth_scopes`.

---

## Callback / deep link

Chosen architecture: **custom URL scheme**, not Universal Links / Android App Links HTTPS.

Laravel already finishes OAuth on HTTPS (`/workspace/channels/{instagram|facebook|tiktok}/callback` and the WhatsApp hosted POST). After success it redirects to the app. A custom scheme avoids hosting `apple-app-site-association` / Digital Asset Links for this return hop.

- Android: intent-filter `hasim://channels/oauth`, `flutter_deeplinking_enabled=false` so `app_links` receives the URI.
- iOS: `CFBundleURLTypes` scheme `hasim`, `FlutterDeepLinkingEnabled=false`. No Associated Domains entitlement (not needed for this scheme).
- Flutter: `AppLinksChannelCallbackSource` + `LaunchMode.externalApplication`. Workspace id on the callback is ignored if it does not match the active workspace (no leak into the other tenant’s UI).

Pending OAuth in workspace A is cleared on switch (`isolationKey` → `reload(reset: true)`).

---

## Security

- OAuth state: encrypted JSON + cache nonce, consume-once, bound to user + workspace + purpose + `return_to`.
- WhatsApp / Facebook page attempts: encrypted cache, consume-once, bound to user + workspace.
- Mobile JSON `assertNoSecrets` rejects `client_secret`, `app_secret`, `access_token`, `refresh_token`, `bot_token` keys/values. Flutter `_rejectSecrets` does the same.
- Independent Worker: `GET/POST /channels*` → 403.
- Regular `member` role: 403 on connect/disconnect.
- Unauthenticated: 401.
- Workspace B cannot finish workspace A’s Facebook page attempt.
- Instagram account already on workspace A cannot be connected to workspace B.
- Web callbacks still reject a different session user when `return_to=web`.
- Instagram token-exchange logs are redacted. Mobile responses do not include ciphertext fields.

---

## Workspace isolation

`GET /channels` uses `WorkspaceScopedModel` queries under `workspace.resolve`. Flutter `ChannelsController` resets items, pending OAuth, and Facebook page choices when `auth.isolationKey` changes. A deep link with another `workspace` query value does not refresh the current list.

---

## Flutter UX

`channels_screen` keeps the existing cards. Native actions:

- ربط / إعادة ربط
- قطع الاتصال
- متصل / غير متصل
- loading on the tapped channel
- snackbar success / error / cancel
- Telegram token sheet
- Facebook page sheet
- Email (and any channel with `can_connect_in_app=false`) still shows **إدارة عبر Web** as documented fallback

RTL Arabic. No new giant screen. `manageUrl` is not the primary connect path when native is enabled.

Provider differences stay in `connection_mode`: `embedded_signup` | `oauth` | `bot_token` | `web_only`. There is no single `ChannelConnectionFlow` class that would force Telegram through OAuth or WhatsApp through redirect OAuth.

---

## Laravel files changed

**Added**

- `config/channel_connect.php`
- `app/Support/ChannelConnect/ChannelConnectReturn.php`
- `app/Support/ChannelConnect/ChannelConnectAttemptStore.php`
- `app/Support/ChannelConnect/ChannelOAuthCallbackHandler.php`
- `app/Services/Channel/ChannelConnectionService.php`
- `app/Services/Meta/Facebook/FacebookPageSelectionRequired.php`
- `app/Http/Controllers/Api/Mobile/V1/ChannelConnectController.php`
- `app/Http/Controllers/Mobile/WhatsAppMobileSignupController.php`
- `resources/views/mobile/channel-connect/whatsapp.blade.php`
- `resources/views/mobile/channel-connect/whatsapp-expired.blade.php`
- `tests/Feature/Mobile/MobileChannelConnectTest.php`
- `tests/Unit/Support/MetaOAuthStateTest.php`

**Modified**

- `app/Http/Controllers/Api/Mobile/V1/ChannelController.php`
- `app/Http/Controllers/Workspace/{Facebook,Instagram,TikTok}ChannelConnectController.php`
- `app/Services/Meta/Facebook/FacebookConnectService.php`
- `app/Services/Meta/Instagram/InstagramConnectService.php`
- `app/Services/TikTok/TikTokConnectService.php`
- `app/Services/Telegram/TelegramConnectService.php`
- `app/Services/Telegram/TelegramApiClient.php` (`deleteWebhook`)
- `app/Services/WhatsApp/WhatsAppEmbeddedSignupService.php` (`disconnectWorkspace`)
- `app/Support/Meta/MetaOAuthState.php` (`return_to`, peek, consume-once nonce)
- `routes/mobile.php`
- `routes/web.php`
- `bootstrap/app.php` (CSRF except `mobile/channel-connect/whatsapp/*`)

## Flutter files changed

- `apps/hasim/lib/core/config/app_config.dart`
- `apps/hasim/lib/core/models/models.dart` (`ChannelModel` native flags)
- `apps/hasim/lib/features/settings/data/channel_connection.dart`
- `apps/hasim/lib/features/settings/data/channel_connection_runtime.dart`
- `apps/hasim/lib/features/settings/data/plan_channel_repository.dart`
- `apps/hasim/lib/features/settings/providers/channels_controller.dart`
- `apps/hasim/lib/features/settings/presentation/channels_screen.dart`
- `apps/hasim/pubspec.yaml` / `pubspec.lock` (`app_links: ^6.4.0`)
- `apps/hasim/android/app/src/main/AndroidManifest.xml`
- `apps/hasim/ios/Runner/Info.plist`
- `apps/hasim/windows/flutter/generated_plugin_registrant.cc`
- `apps/hasim/windows/flutter/generated_plugins.cmake`
- `apps/hasim/test/channel_oauth_callback_test.dart`
- `apps/hasim/test/channels_connection_test.dart`

## Routes added/changed

- Added public `GET/POST /mobile/channel-connect/whatsapp/{token}`
- Added mobile connect/disconnect/Facebook pages
- Instagram / Facebook / TikTok **web** callbacks: `withoutMiddleware` `Authenticate`, `EnsureWorkspaceSelected`, `EnsureWorkspaceMembership` so Laravel can finish a mobile OAuth hop without a browser session. State remains the credential. Web `return_to` still requires the matching signed-in user.

---

## Tests

**Laravel (passed)**

- `tests/Feature/Mobile/MobileChannelConnectTest.php` — 24 tests: list without secrets, start all providers, WhatsApp complete + replay, Facebook single/multi page, Instagram/TikTok callbacks, Telegram token, invalid state, web user mismatch, cross-workspace page attempt, member 403, unauthenticated 401, Independent Worker 403 on list+connect, disconnect WhatsApp/Facebook/Instagram/TikTok/Telegram, Instagram reconnect, Instagram duplicate workspace.
- `tests/Unit/Support/MetaOAuthStateTest.php` — nonce consume, peek, purpose mismatch.
- `tests/Feature/Feature/Meta/InstagramChannelConnectTest.php` + `tests/Feature/Feature/Workspace/ChannelsPageTest.php` — existing web flows still pass.

**Flutter (passed)**

- Channel list, start WhatsApp / Meta / Telegram / TikTok
- Instagram + TikTok system browser
- Loading → connected after callback
- Failure + cancel browser + cancelled callback
- Telegram sheet + token connect without browser
- Disconnect / reconnect
- Workspace switch
- Authorization error
- Repository secret rejection
- Deep-link parse (`success`, `select_page`, ignore unrelated URLs)

`flutter analyze`: **No issues found.**

Channel / auth / settings / workspace isolation tests: **passed.**

Full Flutter suite: **430 passed, 2 failed**, both pre-existing and unrelated to channels:

- `app_config_test.dart`: default production fallback passes release host validation
- `inner_chrome_corner_test.dart`: inner chrome corner stays a fixed overlay and ignores hits

No Android or iOS device run against live providers.

---

## Builds / platform

| Platform | Change |
| --- | --- |
| Android | Custom scheme intent-filter; Flutter engine deep linking disabled |
| iOS | `hasim` URL scheme; Flutter engine deep linking disabled |
| Universal Links / App Links HTTPS | Not added. Laravel HTTPS callback + custom-scheme return is the intended hop |
| New dependency | `app_links` only |

---

## Remaining blockers

1. **Live provider OAuth** — not verified on a device with real Meta / TikTok / Telegram credentials.
2. **Email** — still `web_only` + `manageUrl`. Out of the four required channels.
3. **Missing env** — if Meta/TikTok/WhatsApp config is empty, `can_connect_in_app` is false and the Web fallback button remains. That is correct, not a fake native flow.
4. **Feature flag `whatsapp`** — same gate as the web channels page; native connect is 403 without it.
5. **WhatsApp hosted page** — required by Meta Embedded Signup JS SDK. Users leave the Flutter UI for Facebook’s signup, then return via `hasim://`. They do not log into the Hasim web app.

No channel was faked into an unsafe WebView OAuth. None of the four required channels is blocked from the native path when provider config is present.

---

## Results

| Channel | Start | OAuth | Callback | Flutter Return | Status | Disconnect | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| WhatsApp | Native `POST /connect` → hosted signup URL | Meta Embedded Signup (JS SDK on Laravel page) | Laravel consumes attempt + code | `hasim://channels/oauth` | `GET /channels` | Supported | **Done** (code + HTTP tests; not live Meta) |
| Facebook Messenger | Native `POST /connect` | Facebook dialog | Laravel callback; extra page select if needed | `hasim://` then optional page sheet | `GET /channels` | Supported | **Done** (code + HTTP tests; not live Facebook) |
| Instagram | Native `POST /connect` | Instagram Business Login | Laravel sessionless mobile callback | `hasim://channels/oauth` | `GET /channels` | Supported | **Done** (code + HTTP tests; not live Instagram) |
| Telegram | Native `POST /connect` + `bot_token` | None (BotFather token) | N/A | In-app connected | `GET /channels` | Supported | **Done** (code + HTTP tests; not live Bot API) |
| TikTok | Native `POST /connect` | TikTok Business OAuth | Laravel sessionless mobile callback | `hasim://channels/oauth` | `GET /channels` | Supported | **Done** (code + HTTP tests; not live TikTok) |

Stopped here. No next phase.
