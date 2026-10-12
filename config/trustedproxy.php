<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs/CIDRs, or the special value REMOTE_ADDR to trust the
    | immediate upstream only. When empty, X-Forwarded-* headers are ignored
    | (safe for direct local/testing access).
    |
    | Documented production stack: Nginx terminates TLS and forwards to PHP-FPM
    | on the same Linux host. Start with loopback + REMOTE_ADDR; add external
    | load balancer / CDN private IPs when the edge sits in front of Nginx.
    |
    | Never commit real production IPs here — set TRUSTED_PROXIES in host .env.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Trusted forwarded headers
    |--------------------------------------------------------------------------
    |
    | Standard X-Forwarded-* set used by Nginx, HAProxy, and common L7 load
    | balancers. Do not enable Forwarded (RFC 7239) unless the edge sends it.
    |
    */

    'headers' => env('TRUSTED_PROXY_HEADERS', 'standard'),

];
