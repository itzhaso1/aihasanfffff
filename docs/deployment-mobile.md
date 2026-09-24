# Deployment notes (Hasim Mobile API)

## Required services

- PHP-FPM + Nginx (same Laravel app — no separate mobile backend)
- Queue worker: `php artisan queue:work --queue=emails,default` (inbox sync on `emails`, other jobs on `default`)
- Scheduler: `* * * * * php artisan schedule:run`
- Redis recommended for cache/queues/broadcasting in staging+

## Environment

```
BROADCAST_CONNECTION=log   # local; use reverb/pusher/redis in staging
FCM_ENABLED=false
FCM_SERVER_KEY=
FCM_PROJECT_ID=
SANCTUM_STATEFUL_DOMAINS=  # keep empty for pure mobile token clients
```

## Realtime

1. Configure a real broadcast driver (Laravel Reverb / Pusher / Ably).
2. Mobile authenticates private channels via Sanctum (`/api/broadcasting/auth`).
3. Channel auth rules live in `routes/channels.php`.

## Push

**Status (code truth):** token registration and `SendPushNotificationJob` scaffolding exist, but **FCM HTTP delivery is not implemented** and the Hasim Flutter app uses `NoopPushService` (no `firebase_messaging` token registration yet). `SendPushNotificationJob` is also **not dispatched from production code paths** today — only tests invoke it directly.

| Layer | State |
| --- | --- |
| `POST /api/mobile/v1/devices` | Stores workspace-scoped `DevicePushToken` rows |
| `SendPushNotificationJob` | Membership + preference gates; fail-closed without `workspaceId` |
| FCM HTTP client | **Missing** — when `FCM_ENABLED=true`, job logs a warning and exits without delivery |
| Flutter client | **Noop** — does not obtain/register FCM tokens |

Environment (`.env`, never commit secrets):

```
FCM_ENABLED=false
FCM_SERVER_KEY=
FCM_PROJECT_ID=
```

When disabled (default), the job logs `Push notification skipped: FCM provider not configured` and completes without claiming delivery.

When enabled without an HTTP client, the job logs `Push notification not delivered: FCM HTTP client is not implemented` — **no fake success**.

### MANUAL STAGING CHECK REQUIRED

Real push delivery cannot be verified by PHPUnit alone. After implementing FCM HTTP v1 + Flutter registration:

1. **Firebase (server):** Create/use a Firebase project; create a **service account** with FCM send permission; store credentials only in staging/production `.env` / secret manager (not in git). Decide HTTP v1 (recommended) vs legacy server key — code currently reads `FCM_SERVER_KEY` but does not call FCM yet.
2. **Flutter (Hasim app):** Replace `NoopPushService` with `firebase_messaging`; add platform Firebase config files via secure CI/build secrets (not hard-coded API keys in repo); on token refresh call `POST /api/mobile/v1/devices` with `provider=fcm`.
3. **Backend wiring:** Dispatch `SendPushNotificationJob` from the relevant notification events (new inbound message, etc.) with explicit `workspaceId`.
4. **Prove delivery:** Log in on a physical device/emulator with Google Play services; trigger an event that queues the job; confirm notification arrives and deep-link opens the expected route (`notification_deep_link.dart`).
5. **Invalid token:** Send with a revoked/expired token; confirm backend marks the `DevicePushToken` revoked (to be implemented with the HTTP client) and the job does not retry forever on permanent FCM errors.

Until the above is done, treat mobile push as **not production-ready** even if `FCM_ENABLED=true`.

## CORS

Configure `config/cors.php` for any browser-based tooling. Native mobile apps do not need `Access-Control-Allow-Origin: *`.

## Migrations

Run:

```
php artisan migrate
```

Includes conversation read/mute state, message attachments, device push tokens, idempotency keys, notification preferences.
