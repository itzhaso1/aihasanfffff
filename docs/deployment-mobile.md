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

**Status (code truth):** company staff can register an FCM token via `POST /api/mobile/v1/devices`. `SendPushNotificationJob` sends FCM when `FCM_ENABLED=true` and credentials exist. Inbound inbox messages dispatch one job per company member device. Independent Worker push is intentionally unchanged.

| Layer | State |
| --- | --- |
| `POST /api/mobile/v1/devices` | Stores workspace-scoped `DevicePushToken` rows |
| `SendPushNotificationJob` | Membership + preference gates; fail-closed without `workspaceId`; invalid FCM tokens revoked |
| FCM HTTP client | HTTP v1 with service-account JSON, or legacy `FCM_SERVER_KEY` |
| Flutter client | `firebase_messaging` token source for Android/iOS company accounts |

Environment (`.env`, never commit secrets):

```
FCM_ENABLED=false
FCM_SERVER_KEY=
FCM_PROJECT_ID=
FCM_CREDENTIALS=
FCM_CREDENTIALS_PATH=
```

When disabled (default), the job logs `Push notification skipped: FCM provider not configured` and completes without claiming delivery.

Flutter also needs Firebase client options at build time (`FIREBASE_ANDROID_*` dart-defines). See `HASIM_CHAT_COMPANY_PUSH_REPAIR_2026-10-06.md`.

## CORS

Configure `config/cors.php` for any browser-based tooling. Native mobile apps do not need `Access-Control-Allow-Origin: *`.

## Migrations

Run:

```
php artisan migrate
```

Includes conversation read/mute state, message attachments, device push tokens, idempotency keys, notification preferences.
