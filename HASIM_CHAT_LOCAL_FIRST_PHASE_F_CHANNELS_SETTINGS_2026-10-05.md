# Hasim Chat — Local-First Phase F (Channels + remaining settings)

**Date:** 2026-10-05  
**Scope:** Channel list, current plan, plan catalog, and device-session display data on the existing Drift database.  
**Schema:** v7 → v8. No second database and no Laravel change.

Connect, disconnect, Facebook page selection, and session revoke stay server-confirmed. Company account registration was already native and was not rebuilt.

---

## 1. What shipped

`local_company_cache` stores one JSON payload per company resource:

- `workspace_id`
- `resource` (`channels`, `plan`, `plans`, `sessions`)
- `payload_json`
- `cached_at`

The primary key is `(workspace_id, resource)`.

Screens that already had a saved row paint it first, then refresh in the background. The first open with an empty cache still waits for the network once. A failed refresh keeps the saved row.

Logout (`InboxLocalStore.clearAll`) deletes `local_company_cache` with the rest of the local session. Clearing one workspace deletes only that workspace’s company cache.

## 2. What is now local-first

| Read | Contract | Cache |
| --- | --- | --- |
| Channel list | `GET /channels` | `channels`, after dropping `bot_token`, `access_token`, `refresh_token`, `client_secret`, `app_secret`, and any `manage_url` that contains `token`, `secret`, or `code=` |
| Current plan | `GET /plan` | `plan` |
| Plan catalog | `GET /plans` | `plans` |
| Device sessions | `GET /sessions` | `sessions`, display fields only: id, name, device name, device type, last used, current flag |

Channel rows for workspace A are not returned for workspace B.

`GET /sessions` is the authenticated user’s Sanctum sessions, not a workspace resource. The snapshot is still stored under the active `workspace_id`, so a workspace clear or logout removes it and another workspace cannot read that copy. The cache does not store the token, IP address, or user agent.

## 3. What stays network-only

- **Connect, disconnect, and Facebook page choice.** `POST /channels/{channel}/connect`, `POST /channels/{channel}/disconnect`, and the Facebook pages routes already exist. They run only after the server answers. The bot token is sent on the connect request and is not written to SQLite or the text outbox. OAuth and embedded signup are not safe to retry from an outbox.
- **Web-only channels.** When `connection_mode` is `web_only`, the existing screen still opens `manageUrl`. A normal manage URL is kept in the cache. A URL that carries a token, secret, or `code=` is dropped.
- **Session revoke.** `DELETE /sessions/{tokenId}` and `DELETE /sessions` stay online, then the list reloads.
- **Plan purchase or cancel.** The mobile contract is read-only (`GET /plan`, `GET /plans`). Checkout remains on the web subscription flow.
- **Branding.** `GET /branding` exists and `BrandingRepository` can call it. No Hasim Chat screen reads it, so it was not cached.
- **Push device registration.** `POST /devices` carries the push token. That token stays out of SQLite.
- **AI settings and employees.** Out of this phase. Those repositories were not changed.
- **Independent Worker registration.** Still web-only at `/workers/register`. Company signup from the app is already `POST /api/mobile/v1/auth/register` from the earlier registration work.

## 4. Missing Laravel / Mobile contracts

No new mobile route was required for this phase, and none was added.

Not available on mobile, so not invented:

- Independent Worker self-registration
- Subscription checkout or cancel
- A safe retry contract for channel connect or disconnect

## 5. Files

- `apps/hasim/lib/core/local_db/inbox_database.dart`
- `apps/hasim/lib/core/local_db/inbox_local_store.dart`
- `apps/hasim/lib/core/local_db/settings_local_store.dart`
- `apps/hasim/lib/core/di/providers.dart`
- `apps/hasim/lib/features/settings/data/plan_channel_repository.dart`
- `apps/hasim/lib/features/settings/data/session_repository.dart`
- `apps/hasim/lib/features/settings/providers/channels_controller.dart`
- `apps/hasim/lib/features/settings/presentation/plans_screen.dart`
- `apps/hasim/lib/features/more/presentation/security_sessions_screen.dart`
- `apps/hasim/test/local_first_phase_f_test.dart`

No Laravel file changed. Flutter calls used here are the existing `/channels`, `/plan`, `/plans`, and `/sessions` routes in `routes/mobile.php`.

## 6. flutter analyze

`flutter analyze` in `apps/hasim`: **No issues found.**

## 7. Tests

`apps/hasim/test/local_first_phase_f_test.dart` covers:

- channel cache drops a bot token and a tokenized manage URL
- workspace 4 cannot read workspace 9’s channels
- session cache drops token and IP
- logout `clearAll` removes channels, sessions, and inbox rows
- a failed `GET /channels` returns the saved list
- `POST /channels/{channel}/connect` does not insert an outbox row and does not store the bot token

Full Flutter suite: **465 passed, 2 failed.**

The two failures are the same pre-existing cases, and this phase did not change them:

- `app_config_test.dart` — production host `http://127.0.0.1:8000` fails the HTTPS check
- `inner_chrome_corner_test.dart` — overlay size 5×4 instead of 4×4

No new failure.
