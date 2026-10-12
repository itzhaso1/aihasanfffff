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
    ],
];
