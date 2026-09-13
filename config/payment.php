<?php

return [
    'default_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'local'),

    'providers' => [
        'local' => [
            'enabled' => true,
            'webhook_secret' => env('LOCAL_PAYMENT_WEBHOOK_SECRET'),
            'webhook_tolerance_seconds' => (int) env('LOCAL_PAYMENT_WEBHOOK_TOLERANCE_SECONDS', 300),
        ],
        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', false),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
        ],
    ],
];
