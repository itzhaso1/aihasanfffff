<?php

return [
    'types' => [
        'individual',
        'company',
        'store',
    ],

    /*
    |--------------------------------------------------------------------------
    | Base feature matrix
    |--------------------------------------------------------------------------
    |
    | The final access decision is:
    | workspace type defaults + active plan features + per-workspace overrides.
    |
    */
    'features_by_type' => [
        'individual' => [
            'conversations',
            'smart_replies',
            'ai',
            'subscription',
            'usage',
            'whatsapp',
        ],
        'company' => [
            'dashboard',
            'products',
            'categories',
            'inventory',
            'customers',
            'orders',
            'pos',
            'qr_menu',
            'conversations',
            'messages',
            'smart_replies',
            'ai',
            'payments',
            'payment_gateway',
            'finance',
            'employees',
            'roles_permissions',
            'subscription',
            'usage',
            'analytics',
            'whatsapp',
            'email',
            'crm',
            'advanced_customers',
            'api',
            'white_label',
            'audit',
            'feature_overrides',
        ],
        'store' => [
            'dashboard',
            'products',
            'categories',
            'inventory',
            'customers',
            'orders',
            'pos',
            'qr_menu',
            'conversations',
            'messages',
            'smart_replies',
            'ai',
            'payments',
            'payment_gateway',
            'finance',
            'employees',
            'roles_permissions',
            'subscription',
            'usage',
            'analytics',
            'whatsapp',
            'email',
            'crm',
            'advanced_customers',
            'api',
            'white_label',
            'audit',
            'feature_overrides',
        ],
    ],
];
