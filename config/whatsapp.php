<?php

return [
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET', env('META_APP_SECRET')),
    'meta_app_id' => env('META_APP_ID', env('FACEBOOK_CLIENT_ID')),
    'meta_app_secret' => env('META_APP_SECRET', env('WHATSAPP_APP_SECRET', env('FACEBOOK_CLIENT_SECRET'))),
    'embedded_signup_config_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
    'embedded_signup_redirect_uri' => env('WHATSAPP_EMBEDDED_SIGNUP_REDIRECT_URI'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
];
