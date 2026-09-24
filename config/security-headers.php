<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global security response headers
    |--------------------------------------------------------------------------
    |
    | Applied to web + API middleware groups. CSP is intentionally omitted
    | until Vite/Alpine nonce architecture is in place (see docs/deployment.md).
    |
    */

    'enabled' => filter_var(env('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL),

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => env('SECURITY_HEADERS_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'X-Frame-Options' => env('SECURITY_HEADERS_FRAME_OPTIONS', 'SAMEORIGIN'),
    ],

    'hsts' => [
        // Send only on HTTPS requests. Default on in production.
        'enabled' => filter_var(
            env('SECURITY_HEADERS_HSTS', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOL
        ),
        'max_age' => (int) env('SECURITY_HEADERS_HSTS_MAX_AGE', 31536000),
    ],

];
