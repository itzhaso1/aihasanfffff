<?php

return [
    'app_id' => env('META_APP_ID', env('FACEBOOK_CLIENT_ID')),
    'app_secret' => env('META_APP_SECRET', env('WHATSAPP_APP_SECRET', env('FACEBOOK_CLIENT_SECRET'))),
    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN')),
    'graph_api_version' => env('META_GRAPH_API_VERSION', env('WHATSAPP_API_VERSION', 'v20.0')),
    'instagram_oauth_redirect_uri' => env('META_INSTAGRAM_OAUTH_REDIRECT_URI'),
    'instagram_oauth_scopes' => env('META_INSTAGRAM_OAUTH_SCOPES', ''),
    'facebook_oauth_redirect_uri' => env('META_FACEBOOK_OAUTH_REDIRECT_URI'),
    'facebook_oauth_scopes' => env(
        'META_FACEBOOK_OAUTH_SCOPES',
        'pages_messaging,pages_show_list,pages_read_engagement',
    ),
];
