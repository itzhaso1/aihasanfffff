# Production realtime (Reverb + inbox queues)

P1 uses **Laravel Reverb** as the single WebSocket transport (Pusher-compatible protocol). Clients: Laravel Echo (web) and Pusher client (Hasim mobile).

## Required `.env` (host only — never commit secrets)

```
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=hasim.online
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
INBOX_QUEUE_INBOUND=inbound
INBOX_QUEUE_REALTIME=realtime
INBOX_QUEUE_AI=ai
```

## Processes (Supervisor)

| Template | Role |
| --- | --- |
| `deploy/supervisor/hasem-reverb.conf` | WebSocket server (`artisan reverb:start`) |
| `deploy/supervisor/hasem-worker-inbound.conf` | Meta inbound persistence |
| `deploy/supervisor/hasem-worker-realtime.conf` | Broadcast job queue |
| `deploy/supervisor/hasem-worker-ai.conf` | AI replies |
| `deploy/supervisor/hasem-worker-emails.conf` | Email sync |
| `deploy/supervisor/hasem-worker.conf` | Residual `default` queue |

Recommended starting counts on a single app server (adjust after measuring queue wait):

| Program | `numprocs` |
| --- | --- |
| `hasem-reverb` | 1 |
| `hasem-worker-inbound` | 2 |
| `hasem-worker-realtime` | 2 |
| `hasem-worker-ai` | 1 |
| `hasem-worker-emails` | 1 |
| `hasem-worker` | 1 |

## Reverse proxy / Cloudflare

- Terminate TLS at Nginx/Cloudflare; proxy WebSocket upgrades to `127.0.0.1:8080` (or run Reverb on a private port).
- Allow `/app/{key}` WebSocket path and `/apps/{id}` HTTP APIs per [Laravel Reverb docs](https://laravel.com/docs/reverb).
- Session web auth: `POST /broadcasting/auth` (cookie session).
- Mobile Sanctum auth: `POST /api/broadcasting/auth`.

## Deploy

```bash
php artisan migrate --force
npm run build
php artisan config:cache
php artisan queue:restart
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl restart hasem-reverb hasem-worker-inbound:* hasem-worker-realtime:* hasem-worker-ai:*
php artisan runtime:check --production-strict --require-redis
```

## Health

- Reverb log: `storage/logs/reverb.log`
- Realtime queue depth: Redis `LLEN` on `queues:realtime` (prefix depends on `REDIS_PREFIX`)
- Structured logs: `inbox.realtime.published`, `inbox.inbound.job_complete` (no message bodies)

## Rollback

Set `BROADCAST_CONNECTION=log` and restart workers; inbox remains usable via DB + mobile polling fallback. Do not roll back inbound migrations.

## Latency measurement

Compare log timestamps:

1. `meta.webhook.ingress` (`received_at`)
2. `inbox.inbound.job_complete` (`duration_ms`, `message_id`)
3. `inbox.realtime.published` (`duration_ms`)

Client-side delivery requires browser/mobile devtools or future metrics sink (not claimed in CI).
