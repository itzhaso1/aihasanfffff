# Deployment notes (Hasim Mobile API)

## Required services

- PHP-FPM + Nginx (same Laravel app — no separate mobile backend)
- Queue worker: `php artisan queue:work` (push jobs, email send, AI)
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

Until `FCM_ENABLED=true` and server credentials exist, `SendPushNotificationJob` will not claim delivery success.

## CORS

Configure `config/cors.php` for any browser-based tooling. Native mobile apps do not need `Access-Control-Allow-Origin: *`.

## Migrations

Run:

```
php artisan migrate
```

Includes conversation read/mute state, message attachments, device push tokens, idempotency keys, notification preferences.
