<?php

/**
 * Plan catalog helpers for seeding + Platform UI labels.
 *
 * IMPORTANT: Runtime entitlement decisions come from the `plans` table
 * (features/limits JSON) via FeatureAccessService — not from this file.
 * Editing a plan in Platform Dashboard changes access without code changes.
 */
return [
    'currency' => env('PLAN_CURRENCY', 'SAR'),

    /*
    |--------------------------------------------------------------------------
    | Commercial feature catalog (labels for Platform Dashboard)
    |--------------------------------------------------------------------------
    */
    'commercial_features' => [
        'ai' => 'الذكاء الاصطناعي',
        'whatsapp' => 'واتساب',
        'pos' => 'نقطة البيع', // Legacy Platform key; Chat does not gate on this
        'qr_menu' => 'قائمة QR',
        'finance' => 'المالية والفواتير', // Legacy Platform key; Chat does not gate on this
        'email' => 'البريد الاحترافي',
        'api' => 'واجهة البرمجة API',
        'analytics' => 'التحليلات',
        'advanced_customers' => 'إدارة العملاء المتقدمة',
        'customers' => 'العملاء (أساسي)',
        'products' => 'المنتجات',
        'categories' => 'التصنيفات',
        'inventory' => 'المخزون', // Legacy Platform key; Chat Catalog inventory is separate
        'orders' => 'الطلبات',
        'payments' => 'المدفوعات',
        'payment_gateway' => 'بوابة الدفع',
        'employees' => 'الموظفون',
        'roles_permissions' => 'الأدوار والصلاحيات',
        'conversations' => 'المحادثات',
        'messages' => 'الرسائل',
        'smart_replies' => 'الردود الذكية',
        'dashboard' => 'لوحة التحكم',
        'subscription' => 'الاشتراكات',
        'usage' => 'الاستخدام',
        'white_label' => 'العلامة البيضاء',
        'audit' => 'التدقيق',
        'feature_overrides' => 'تجاوزات الميزات',
    ],

    'limit_fields' => [
        'team_members' => 'أعضاء الفريق',
        'users' => 'المستخدمون',
        'customers' => 'العملاء',
        'products' => 'المنتجات',
        'orders' => 'الطلبات',
        'ai_usage' => 'رموز / استخدام الذكاء الاصطناعي',
        'whatsapp_messages' => 'رسائل واتساب',
        'email_sends' => 'رسائل البريد',
        'storage_mb' => 'التخزين (ميجابايت)',
        'api_calls' => 'طلبات API',
    ],

    'meters' => [
        'ai_tokens' => ['overage' => 'hard_block', 'label' => 'رموز الذكاء الاصطناعي'],
        'ai_usage' => ['overage' => 'hard_block', 'label' => 'استخدام الذكاء الاصطناعي'],
        'whatsapp_messages' => ['overage' => 'hard_block', 'label' => 'رسائل واتساب'],
        'email_sends' => ['overage' => 'hard_block', 'label' => 'رسائل البريد'],
        'storage_mb' => ['overage' => 'upgrade_required', 'label' => 'التخزين'],
        'api_calls' => ['overage' => 'hard_block', 'label' => 'استدعاءات API'],
        'orders' => ['overage' => 'upgrade_required', 'label' => 'الطلبات'],
        'products' => ['overage' => 'upgrade_required', 'label' => 'المنتجات'],
        'customers' => ['overage' => 'upgrade_required', 'label' => 'العملاء'],
        'team_members' => ['overage' => 'upgrade_required', 'label' => 'أعضاء الفريق'],
        'users' => ['overage' => 'upgrade_required', 'label' => 'المستخدمون'],
    ],

    'meter_aliases' => [
        'ai_tokens' => ['ai_usage'],
        'ai_usage' => ['ai_tokens'],
        'team_members' => ['users'],
        'users' => ['team_members'],
    ],

    'feature_aliases' => [
        'advanced_customers' => ['crm'],
        'crm' => ['advanced_customers'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default seed matrix (copied into plans.features / plans.limits on seed)
    | After seeding, Platform Dashboard edits win.
    |--------------------------------------------------------------------------
    */
    'feature_matrix' => [
        'starter' => [
            'features' => [
                'dashboard', 'customers', 'subscription', 'usage', 'conversations',
                'messages', 'smart_replies', 'ai', 'payments',
            ],
            'limits' => [
                'ai_usage' => 2000,
                'customers' => 200,
                'team_members' => 3,
                'users' => 3,
                'products' => 50,
                'orders' => 100,
                'whatsapp_messages' => 0,
                'email_sends' => 200,
                'storage_mb' => 512,
                'api_calls' => 0,
            ],
        ],
        'pro' => [
            'features' => [
                'dashboard', 'customers', 'subscription', 'usage',
                'conversations', 'messages', 'smart_replies', 'ai', 'payments',
                'payment_gateway', 'pos', 'qr_menu', 'finance', 'products',
                'categories', 'inventory', 'orders', 'email', 'analytics',
                'employees', 'whatsapp',
            ],
            'limits' => [
                'ai_usage' => 50000,
                'customers' => 2000,
                'team_members' => 15,
                'users' => 15,
                'products' => 1000,
                'orders' => 3000,
                'whatsapp_messages' => 1000,
                'email_sends' => 2000,
                'storage_mb' => 5120,
                'api_calls' => 0,
            ],
        ],
        'business' => [
            'features' => [
                'dashboard', 'customers', 'subscription', 'usage',
                'conversations', 'messages', 'smart_replies', 'ai', 'payments',
                'payment_gateway', 'pos', 'qr_menu', 'finance', 'products',
                'categories', 'inventory', 'orders', 'email', 'analytics',
                'employees', 'whatsapp', 'roles_permissions', 'api',
                'advanced_customers', 'crm',
            ],
            'limits' => [
                'ai_usage' => 200000,
                'customers' => 20000,
                'team_members' => 50,
                'users' => 50,
                'products' => 10000,
                'orders' => 50000,
                'whatsapp_messages' => 10000,
                'email_sends' => 20000,
                'storage_mb' => 51200,
                'api_calls' => 50000,
            ],
        ],
        'enterprise' => [
            'features' => [
                'dashboard', 'customers', 'subscription', 'usage',
                'conversations', 'messages', 'smart_replies', 'ai', 'payments',
                'payment_gateway', 'pos', 'qr_menu', 'finance', 'products',
                'categories', 'inventory', 'orders', 'email', 'analytics',
                'employees', 'whatsapp', 'roles_permissions', 'api',
                'advanced_customers', 'crm', 'white_label', 'audit', 'feature_overrides',
            ],
            'limits' => [
                'ai_usage' => 1000000,
                'customers' => 200000,
                'team_members' => 200,
                'users' => 200,
                'products' => 100000,
                'orders' => 500000,
                'whatsapp_messages' => 100000,
                'email_sends' => 200000,
                'storage_mb' => 512000,
                'api_calls' => 500000,
            ],
        ],
    ],

    /*
    | Matrix rows shown in Platform + Workspace comparison UIs.
    | Values are resolved from Plan.features in the database.
    */
    'comparison_rows' => [
        ['key' => 'ai', 'label' => 'الذكاء الاصطناعي'],
        ['key' => 'whatsapp', 'label' => 'واتساب'],
        ['key' => 'pos', 'label' => 'نقطة البيع'],
        ['key' => 'finance', 'label' => 'المالية والفواتير'],
        ['key' => 'email', 'label' => 'البريد الاحترافي'],
        ['key' => 'api', 'label' => 'واجهة البرمجة API'],
        ['key' => 'analytics', 'label' => 'التحليلات'],
        ['key' => 'advanced_customers', 'label' => 'إدارة العملاء المتقدمة'],
    ],

    /*
    | Public subscription-card copy. A row is shown only when the plan
    | actually has the listed feature keys in plans.features.
    */
    'card_highlights' => [
        'starter' => [
            ['label' => 'إدارة العملاء', 'any' => ['customers']],
            ['label' => 'المحادثات', 'any' => ['conversations', 'messages']],
            ['label' => 'لوحة تحكم أساسية', 'any' => ['dashboard']],
            ['label' => 'استخدام محدود للمزايا', 'any' => ['usage']],
        ],
        'pro' => [
            ['label' => 'كل مميزات المبتدئة', 'any' => ['customers', 'dashboard']],
            ['label' => 'تقارير متقدمة', 'any' => ['analytics']],
            ['label' => 'المنتجات والمخزون', 'any' => ['products']],
            ['label' => 'طلبات التجارة', 'any' => ['orders']],
            ['label' => 'مزايا الذكاء الاصطناعي', 'any' => ['ai']],
            ['label' => 'استخدام أعلى', 'any' => ['usage']],
        ],
        'business' => [
            ['label' => 'كل مميزات الاحترافية', 'any' => ['analytics', 'products', 'orders']],
            ['label' => 'صلاحيات الموظفين', 'any' => ['employees', 'roles_permissions']],
            ['label' => 'إدارة العملاء المتقدمة', 'any' => ['advanced_customers', 'crm']],
            ['label' => 'استخدام أعلى', 'any' => ['usage']],
        ],
        'enterprise' => [
            ['label' => 'كل مميزات الأعمال', 'any' => ['roles_permissions', 'advanced_customers']],
            ['label' => 'صلاحيات متقدمة', 'any' => ['roles_permissions']],
            ['label' => 'استخدام مرن حسب الاحتياج', 'any' => ['usage']],
        ],
    ],

    'legacy_code_tier_map' => [
        'individual_free' => 'starter',
        'individual_pro' => 'pro',
        'company_basic' => 'starter',
        'store_basic' => 'starter',
        'company_starter' => 'starter',
        'store_starter' => 'starter',
        'company_pro' => 'pro',
        'store_pro' => 'pro',
        'company_business' => 'business',
        'store_business' => 'business',
        'company_enterprise' => 'enterprise',
        'store_enterprise' => 'enterprise',
    ],
];
