<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'webhook_tolerance_seconds' => (int) env('RESEND_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'google_ai_studio' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GOOGLE_AI_STUDIO_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash')),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'fcm' => [
        'enabled' => env('FCM_ENABLED', false),
        'server_key' => env('FCM_SERVER_KEY'),
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
        'timeout' => (int) env('FCM_TIMEOUT', 20),
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_PERMANENT_TOKEN'),
    ],

    'hyperpay' => [
        'enabled' => env('HYPERPAY_ENABLED', false),
        'entity_id' => env('HYPERPAY_ENTITY_ID'),
        'access_token' => env('HYPERPAY_ACCESS_TOKEN'),
        'merchant_onboarding_enabled' => env('HYPERPAY_MERCHANT_ONBOARDING_ENABLED', false),
        // Staging-only local_sandbox convenience — never invents a real HyperPay success.
        'merchant_sandbox_auto_approve' => env('HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE', false),
        // HTTP client limits for a future official HyperPay charge client.
        // No base URL / signature / status map is defined here — those are not in-repo.
        'connect_timeout' => (int) env('HYPERPAY_CONNECT_TIMEOUT', 8),
        'timeout' => (int) env('HYPERPAY_TIMEOUT', 20),
    ],

];
