<?php

return [

    'client_key' => env('TIKTOK_CLIENT_KEY'),

    'client_secret' => env('TIKTOK_CLIENT_SECRET'),

    'api_version' => env('TIKTOK_API_VERSION', 'v1.3'),

    'api_base' => env('TIKTOK_API_BASE', 'https://business-api.tiktok.com/open_api'),

    'oauth_authorize_url' => env('TIKTOK_OAUTH_AUTHORIZE_URL', 'https://www.tiktok.com/v2/auth/authorize'),

    'oauth_scopes' => array_values(array_filter(array_map(
        static fn (string $scope): string => trim($scope),
        explode(',', (string) env('TIKTOK_OAUTH_SCOPES', '')),
    ))),

    'redirect_uri' => env('TIKTOK_OAUTH_REDIRECT_URI'),

    'webhook_max_skew_seconds' => (int) env('TIKTOK_WEBHOOK_MAX_SKEW_SECONDS', 300),

    'webhook_callback_url' => env('TIKTOK_WEBHOOK_CALLBACK_URL'),

];
