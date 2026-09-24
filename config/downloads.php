<?php

return [
    /*
    | Store / installer URLs for HASEM apps.
    | Leave empty until a real listing or installer exists. Do not invent public store links.
    |
    | Legacy aliases (chat / Hasim): HASIM_APP_STORE_URL, HASIM_GOOGLE_PLAY_URL, HASIM_WINDOWS_DOWNLOAD_URL
    | Per-app: HASIM_{CHAT|FINANCE|CASHIER}_{APP_STORE|GOOGLE_PLAY|WINDOWS_DOWNLOAD}_URL
    */
    'app_store_url' => env('HASIM_APP_STORE_URL'),
    'google_play_url' => env('HASIM_GOOGLE_PLAY_URL'),
    'windows_url' => env('HASIM_WINDOWS_DOWNLOAD_URL'),

    'apps' => [
        'chat' => [
            'key' => 'chat',
            'name' => 'حاسم شات',
            'eyebrow' => 'صندوق وارد موحّد',
            'tagline' => 'أدر محادثات العملاء والبريد من تطبيق واحد.',
            'description' => 'تطبيق حاسم شات هو واجهة الصندوق الوارد الموحّد: واتساب، إنستغرام، ماسنجر، تيليجرام، تيك توك، والبريد. صُمّم لفرق الدعم والمبيعات التي تريد الرد من الجوال أو سطح المكتب دون فتح تطبيقات المحاسبة أو البيع.',
            'audience' => 'مناسب لفرق خدمة العملاء، المبيعات، وأصحاب المنشآت الذين يديرون المحادثات اليومية مع العملاء.',
            'image' => 'images/landing-inbox.jpg',
            'alt' => 'لقطة من تطبيق حاسم شات',
            'gallery' => [
                ['src' => 'images/landing-inbox.jpg', 'alt' => 'واجهة المحادثات في حاسم شات'],
            ],
            'features' => [
                'صندوق وارد موحّد للقنوات والمحادثات',
                'البريد والردود من نفس مساحة العمل',
                'تحديثات وقصص العملاء',
                'جهات الاتصال والإشعارات',
            ],
            'app_store_url' => env('HASIM_CHAT_APP_STORE_URL', env('HASIM_APP_STORE_URL')),
            'google_play_url' => env('HASIM_CHAT_GOOGLE_PLAY_URL', env('HASIM_GOOGLE_PLAY_URL')),
            'windows_url' => env('HASIM_CHAT_WINDOWS_DOWNLOAD_URL', env('HASIM_WINDOWS_DOWNLOAD_URL')),
        ],
        'finance' => [
            'key' => 'finance',
            'name' => 'حاسم المالية',
            'eyebrow' => 'المالية والمحاسبة',
            'tagline' => 'الفواتير والمدفوعات والتقارير من تطبيق المالية.',
            'description' => 'تطبيق حاسم المالية هو عميل ERP المالي للمنصة: فواتير، مدفوعات، تقارير، وعملاء. الأرقام تُحسب في المنصة وليس داخل التطبيق، حتى تبقى الفواتير والحسابات متوافقة مع مصدر الحقيقة الواحد.',
            'audience' => 'مناسب للمحاسبين، مدراء المالية، وأصحاب المنشآت الذين يتابعون الفوترة والتحصيل والتقارير أثناء التنقل.',
            'image' => 'images/landing-invoicing.jpg',
            'alt' => 'لقطة من تطبيق حاسم المالية',
            'gallery' => [
                ['src' => 'images/landing-invoicing.jpg', 'alt' => 'واجهة الفوترة في حاسم المالية'],
            ],
            'features' => [
                'إنشاء ومتابعة الفواتير',
                'تسجيل المدفوعات والتحصيل',
                'تقارير مالية جاهزة من المنصة',
                'عملاء وعقود من مساحة العمل نفسها',
            ],
            'app_store_url' => env('HASIM_FINANCE_APP_STORE_URL'),
            'google_play_url' => env('HASIM_FINANCE_GOOGLE_PLAY_URL'),
            'windows_url' => env('HASIM_FINANCE_WINDOWS_DOWNLOAD_URL'),
        ],
        'cashier' => [
            'key' => 'cashier',
            'name' => 'حاسم الكاشير',
            'eyebrow' => 'نقطة البيع',
            'tagline' => 'بيع سريع للطاولات والطلبات والمخزون.',
            'description' => 'تطبيق حاسم الكاشير هو نقطة البيع: طلبات، طاولات، منتجات، ومخزون. يعمل مع واجهة الكاشير في المنصة، ويمكنه العمل محليًا ثم المزامنة. لا يخلط المحادثات أو دفتر المالية العام مع شاشة البيع.',
            'audience' => 'مناسب للمحلات، المقاهي، المطاعم، ونقاط البيع التي تحتاج كاشيرًا سريعًا على الجوال أو الجهاز المكتبي.',
            'image' => 'images/landing-cashier.jpg',
            'alt' => 'لقطة من تطبيق حاسم الكاشير',
            'gallery' => [
                ['src' => 'images/landing-cashier.jpg', 'alt' => 'واجهة نقطة البيع في حاسم الكاشير'],
            ],
            'features' => [
                'شاشة بيع سريعة للطلبات',
                'إدارة الطاولات والمنتجات',
                'متابعة المخزون من نقطة البيع',
                'عمل محلي ثم مزامنة مع المنصة',
            ],
            'app_store_url' => env('HASIM_CASHIER_APP_STORE_URL'),
            'google_play_url' => env('HASIM_CASHIER_GOOGLE_PLAY_URL'),
            'windows_url' => env('HASIM_CASHIER_WINDOWS_DOWNLOAD_URL'),
        ],
    ],
];
