<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Token lifetimes (days)
    |--------------------------------------------------------------------------
    |
    | Sanctum `expiration` stays null so these per-token expires_at values
    | remain authoritative. Do not set a global Sanctum expiration that is
    | longer than the shortest client TTL.
    |
    */
    'api_token_days' => (int) env('API_TOKEN_DAYS', 30),
    'mobile_token_days' => (int) env('MOBILE_TOKEN_DAYS', 60),

    'otp' => [
        'ttl_seconds' => 300,
        'max_requests_per_minute' => 5,
        'max_verify_attempts' => 5,
    ],
];
