# Deployment (Linux)

Target production stack:

- **Linux** host
- **Nginx** reverse proxy
- **PHP-FPM** (PHP 8.3+)
- **Queue workers** (`php artisan queue:work`)
- **Scheduler** (`* * * * * php artisan schedule:run`)
- **Certbot** (or other ACME) for TLS
- Database + Redis/cache as configured

## Nginx sketch

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/hasem/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Preserve `Host` and `X-Forwarded-*` when the app sits behind a reverse proxy.

Set `TRUSTED_PROXIES` in the host `.env` so Laravel trusts forwarded headers only from the edge (see `.env.example`). Same-host Nginx + PHP-FPM: `127.0.0.1,::1,REMOTE_ADDR`. If an external load balancer or CDN sits in front of Nginx, add its private IP/CIDR — do not use `*` unless that edge strips client-supplied forwarded headers.

## TLS / Certbot

Issue TLS for the platform hostname with Certbot (or CDN/ACME) on Linux against Nginx.

```bash
certbot --nginx -d app.example.com
```

Automate renewals (`certbot renew` via cron/systemd timer).

## Queue & scheduler

Workers should process payment, WhatsApp, and other application jobs. Scheduler commands live in `routes/console.php`.

Production runtime (Redis queue/cache/session, Supervisor, cron): `docs/operations/production-runtime-runbook.md`.

When `QUEUE_CONNECTION=redis`, set `REDIS_QUEUE_AFTER_COMMIT=true` in production `.env` so queued jobs are pushed only after DB transactions commit. Restart workers after changing queue config (`php artisan queue:restart`).

## Environment

Configure app URL, DB, cache, queue, mail, WhatsApp, AI keys, and payment webhook secrets before go-live.

Global security headers (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, HSTS on HTTPS in production) are applied via `App\Http\Middleware\SecurityHeaders` on web + API responses. CSP is intentionally deferred until Vite/Alpine nonce support exists. See `config/security-headers.php` and `.env.example` (`SECURITY_HEADERS_*`).

HyperPay (merchant settlement — optional until contract ready):

```
HYPERPAY_ENABLED=false
HYPERPAY_ENTITY_ID=
HYPERPAY_ACCESS_TOKEN=
HYPERPAY_MERCHANT_ONBOARDING_ENABLED=false
HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=false
```

Never set `HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=true` in production.

Private merchant documents live under `storage/app/private` — ensure disk permissions and backups. First-party database backup and isolated restore: `docs/operations/backup-restore-runbook.md`.

## Related docs

- `docs/webhook-setup.md`
- `docs/hyperpay.md`
- `docs/merchant-payments.md`
- `docs/billing.md`
