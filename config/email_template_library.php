<?php

$shared = [
    'companyName',
    'customerName',
    'subject',
    'body',
    'ctaLabel',
    'ctaUrl',
    'logoUrl',
    'primaryColor',
    'secondaryColor',
    'backgroundColor',
    'textColor',
];
$invoice = array_merge($shared, ['invoiceNumber', 'amount', 'dueDate']);
$order = array_merge($shared, ['orderNumber', 'orderTotal']);
$contract = array_merge($shared, ['contractTitle', 'contractUrl']);

$categories = [
    'construction' => ['name' => 'المقاولات', 'name_en' => 'Construction', 'description' => 'قوالب المقاولات والمشاريع الإنشائية'],
    'buy-sell' => ['name' => 'البيع والشراء', 'name_en' => 'Buy & Sell', 'description' => 'قوالب البيع والشراء والعروض التجارية'],
    'ecommerce' => ['name' => 'التجارة الإلكترونية', 'name_en' => 'Ecommerce', 'description' => 'قوالب المتاجر الإلكترونية والطلبات'],
    'technology' => ['name' => 'التقنية والبرمجيات', 'name_en' => 'Technology', 'description' => 'قوالب التقنية والبرمجيات'],
    'agriculture' => ['name' => 'الزراعة', 'name_en' => 'Agriculture', 'description' => 'قوالب الزراعة والإنتاج الزراعي'],
    'real-estate' => ['name' => 'العقارات', 'name_en' => 'Real Estate', 'description' => 'قوالب العقارات والتأجير'],
    'services' => ['name' => 'الخدمات', 'name_en' => 'Services', 'description' => 'قوالب شركات الخدمات'],
    'corporate' => ['name' => 'الشركات والمؤسسات', 'name_en' => 'Corporate', 'description' => 'قوالب الشركات والمؤسسات'],
    'restaurants' => ['name' => 'المطاعم والمقاهي', 'name_en' => 'Restaurants', 'description' => 'قوالب المطاعم والمقاهي'],
    'education' => ['name' => 'التعليم والدورات', 'name_en' => 'Education', 'description' => 'قوالب التعليم والدورات'],
    'marketing' => ['name' => 'التسويق', 'name_en' => 'Marketing', 'description' => 'قوالب الحملات التسويقية'],
    'health' => ['name' => 'الصحة', 'name_en' => 'Health', 'description' => 'قوالب الصحة والرعاية'],
    'travel' => ['name' => 'السياحة والسفر', 'name_en' => 'Travel', 'description' => 'قوالب السياحة والسفر'],
    'shipping' => ['name' => 'الشحن والتوصيل', 'name_en' => 'Shipping', 'description' => 'قوالب الشحن والتوصيل'],
    'maintenance' => ['name' => 'الصيانة', 'name_en' => 'Maintenance', 'description' => 'قوالب الصيانة والخدمات الميدانية'],
    'finance' => ['name' => 'التمويل والمحاسبة', 'name_en' => 'Finance', 'description' => 'قوالب التمويل والمحاسبة'],
    'automotive' => ['name' => 'السيارات', 'name_en' => 'Automotive', 'description' => 'قوالب السيارات وخدمات المركبات'],
    'industry' => ['name' => 'الصناعة', 'name_en' => 'Industry', 'description' => 'قوالب الصناعة والتصنيع'],
    'legal' => ['name' => 'العقود والأعمال القانونية', 'name_en' => 'Legal', 'description' => 'قوالب العقود والأعمال القانونية'],
    'generic' => ['name' => 'قالب عام', 'name_en' => 'Generic', 'description' => 'القوالب العامة، وتشمل الهوية الحالية لمساحة العمل'],
];

$catalog = [
    'construction' => [
        'welcome' => ['name' => 'ترحيب المقاولات', 'name_en' => 'Construction Welcome', 'description' => 'ترحيب بعميل أو شريك في نشاط المقاولات', 'variables' => $shared, 'status' => 'active'],
        'notification' => ['name' => 'إشعار المقاولات', 'name_en' => 'Construction Notification', 'description' => 'إشعار عام لمشروع أو مرحلة عمل', 'variables' => $shared],
        'invoice' => ['name' => 'فاتورة المقاولات', 'name_en' => 'Construction Invoice', 'description' => 'إشعار فاتورة أو مستخلص', 'variables' => $invoice],
        'marketing' => ['name' => 'تسويق المقاولات', 'name_en' => 'Construction Marketing', 'description' => 'رسالة تسويقية لخدمات المقاولات', 'variables' => $shared],
    ],
    'buy-sell' => [
        'welcome' => ['name' => 'ترحيب البيع والشراء', 'name_en' => 'Buy & Sell Welcome', 'description' => 'ترحيب بعميل في نشاط البيع والشراء', 'variables' => $shared],
        'notification' => ['name' => 'إشعار البيع والشراء', 'name_en' => 'Buy & Sell Notification', 'description' => 'إشعار صفقة أو عرض', 'variables' => $shared],
        'invoice' => ['name' => 'فاتورة البيع والشراء', 'name_en' => 'Buy & Sell Invoice', 'description' => 'إشعار فاتورة بيع', 'variables' => $invoice],
        'marketing' => ['name' => 'تسويق البيع والشراء', 'name_en' => 'Buy & Sell Marketing', 'description' => 'رسالة تسويقية للعروض', 'variables' => $shared],
    ],
    'ecommerce' => [
        'welcome' => ['name' => 'ترحيب المتجر', 'name_en' => 'Ecommerce Welcome', 'description' => 'ترحيب بعميل المتجر الإلكتروني', 'variables' => $shared],
        'order-confirmation' => ['name' => 'تأكيد الطلب', 'name_en' => 'Order Confirmation', 'description' => 'تأكيد طلب إلكتروني', 'variables' => $order],
        'invoice' => ['name' => 'فاتورة المتجر', 'name_en' => 'Ecommerce Invoice', 'description' => 'فاتورة طلب إلكتروني', 'variables' => $invoice],
        'marketing' => ['name' => 'تسويق المتجر', 'name_en' => 'Ecommerce Marketing', 'description' => 'حملة تسويقية للمتجر', 'variables' => $shared],
    ],
    'technology' => [
        'welcome' => ['name' => 'ترحيب التقنية', 'name_en' => 'Technology Welcome', 'description' => 'ترحيب بعميل تقني', 'variables' => $shared],
        'notification' => ['name' => 'إشعار التقنية', 'name_en' => 'Technology Notification', 'description' => 'إشعار منتج أو تحديث', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق التقنية', 'name_en' => 'Technology Marketing', 'description' => 'رسالة تسويقية لمنتج تقني', 'variables' => $shared],
    ],
    'agriculture' => [
        'welcome' => ['name' => 'ترحيب الزراعة', 'name_en' => 'Agriculture Welcome', 'description' => 'ترحيب بعميل زراعي', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الزراعة', 'name_en' => 'Agriculture Notification', 'description' => 'إشعار موسم أو توريد', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق الزراعة', 'name_en' => 'Agriculture Marketing', 'description' => 'رسالة تسويقية للمنتجات الزراعية', 'variables' => $shared],
    ],
    'real-estate' => [
        'welcome' => ['name' => 'ترحيب العقارات', 'name_en' => 'Real Estate Welcome', 'description' => 'ترحيب بعميل عقاري', 'variables' => $shared],
        'notification' => ['name' => 'إشعار العقارات', 'name_en' => 'Real Estate Notification', 'description' => 'إشعار عقار أو موعد', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق العقارات', 'name_en' => 'Real Estate Marketing', 'description' => 'عرض عقاري تسويقي', 'variables' => $shared],
    ],
    'services' => [
        'welcome' => ['name' => 'ترحيب الخدمات', 'name_en' => 'Services Welcome', 'description' => 'ترحيب بعميل خدمات', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الخدمات', 'name_en' => 'Services Notification', 'description' => 'إشعار موعد أو طلب خدمة', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق الخدمات', 'name_en' => 'Services Marketing', 'description' => 'رسالة تسويقية للخدمات', 'variables' => $shared],
    ],
    'corporate' => [
        'welcome' => ['name' => 'ترحيب الشركات', 'name_en' => 'Corporate Welcome', 'description' => 'ترحيب مؤسسي', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الشركات', 'name_en' => 'Corporate Notification', 'description' => 'إشعار مؤسسي عام', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق الشركات', 'name_en' => 'Corporate Marketing', 'description' => 'رسالة تسويقية مؤسسية', 'variables' => $shared],
    ],
    'restaurants' => [
        'welcome' => ['name' => 'ترحيب المطاعم', 'name_en' => 'Restaurant Welcome', 'description' => 'ترحيب بعميل مطعم أو مقهى', 'variables' => $shared],
        'notification' => ['name' => 'إشعار المطاعم', 'name_en' => 'Restaurant Notification', 'description' => 'إشعار حجز أو طلب', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق المطاعم', 'name_en' => 'Restaurant Marketing', 'description' => 'عرض قائمة أو حملة', 'variables' => $shared],
    ],
    'education' => [
        'welcome' => ['name' => 'ترحيب التعليم', 'name_en' => 'Education Welcome', 'description' => 'ترحيب بمتدرب أو طالب', 'variables' => $shared],
        'notification' => ['name' => 'إشعار التعليم', 'name_en' => 'Education Notification', 'description' => 'إشعار دورة أو موعد', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق التعليم', 'name_en' => 'Education Marketing', 'description' => 'ترويج دورة أو برنامج', 'variables' => $shared],
    ],
    'marketing' => [
        'welcome' => ['name' => 'ترحيب التسويق', 'name_en' => 'Marketing Welcome', 'description' => 'ترحيب لقائمة تسويقية', 'variables' => $shared],
        'notification' => ['name' => 'إشعار التسويق', 'name_en' => 'Marketing Notification', 'description' => 'إشعار حملة', 'variables' => $shared],
        'campaign' => ['name' => 'حملة تسويقية', 'name_en' => 'Marketing Campaign', 'description' => 'قالب حملة تسويقية', 'variables' => $shared],
    ],
    'health' => [
        'welcome' => ['name' => 'ترحيب الصحة', 'name_en' => 'Health Welcome', 'description' => 'ترحيب بعميل صحي', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الصحة', 'name_en' => 'Health Notification', 'description' => 'إشعار موعد أو نتيجة', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق الصحة', 'name_en' => 'Health Marketing', 'description' => 'رسالة توعوية أو تسويقية', 'variables' => $shared],
    ],
    'travel' => [
        'welcome' => ['name' => 'ترحيب السفر', 'name_en' => 'Travel Welcome', 'description' => 'ترحيب بعميل سياحي', 'variables' => $shared],
        'notification' => ['name' => 'إشعار السفر', 'name_en' => 'Travel Notification', 'description' => 'إشعار حجز أو رحلة', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق السفر', 'name_en' => 'Travel Marketing', 'description' => 'عرض رحلة أو باقة', 'variables' => $shared],
    ],
    'shipping' => [
        'welcome' => ['name' => 'ترحيب الشحن', 'name_en' => 'Shipping Welcome', 'description' => 'ترحيب بعميل شحن', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الشحن', 'name_en' => 'Shipping Notification', 'description' => 'تحديث حالة شحنة', 'variables' => $shared],
        'invoice' => ['name' => 'فاتورة الشحن', 'name_en' => 'Shipping Invoice', 'description' => 'فاتورة شحن أو توصيل', 'variables' => $invoice],
        'marketing' => ['name' => 'تسويق الشحن', 'name_en' => 'Shipping Marketing', 'description' => 'عرض خدمة شحن', 'variables' => $shared],
    ],
    'maintenance' => [
        'welcome' => ['name' => 'ترحيب الصيانة', 'name_en' => 'Maintenance Welcome', 'description' => 'ترحيب بعميل صيانة', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الصيانة', 'name_en' => 'Maintenance Notification', 'description' => 'إشعار موعد صيانة', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق الصيانة', 'name_en' => 'Maintenance Marketing', 'description' => 'عرض خدمة صيانة', 'variables' => $shared],
    ],
    'finance' => [
        'welcome' => ['name' => 'ترحيب التمويل', 'name_en' => 'Finance Welcome', 'description' => 'ترحيب بعميل مالي', 'variables' => $shared],
        'notification' => ['name' => 'إشعار التمويل', 'name_en' => 'Finance Notification', 'description' => 'إشعار مالي عام', 'variables' => $shared],
        'invoice' => ['name' => 'فاتورة التمويل', 'name_en' => 'Finance Invoice', 'description' => 'إشعار فاتورة أو كشف', 'variables' => $invoice],
        'marketing' => ['name' => 'تسويق التمويل', 'name_en' => 'Finance Marketing', 'description' => 'عرض خدمة مالية', 'variables' => $shared],
    ],
    'automotive' => [
        'welcome' => ['name' => 'ترحيب السيارات', 'name_en' => 'Automotive Welcome', 'description' => 'ترحيب بعميل سيارات', 'variables' => $shared],
        'notification' => ['name' => 'إشعار السيارات', 'name_en' => 'Automotive Notification', 'description' => 'إشعار صيانة أو طلب', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق السيارات', 'name_en' => 'Automotive Marketing', 'description' => 'عرض سيارة أو خدمة', 'variables' => $shared],
    ],
    'industry' => [
        'welcome' => ['name' => 'ترحيب الصناعة', 'name_en' => 'Industry Welcome', 'description' => 'ترحيب بعميل صناعي', 'variables' => $shared],
        'notification' => ['name' => 'إشعار الصناعة', 'name_en' => 'Industry Notification', 'description' => 'إشعار طلب أو توريد', 'variables' => $shared],
        'marketing' => ['name' => 'تسويق الصناعة', 'name_en' => 'Industry Marketing', 'description' => 'عرض صناعي', 'variables' => $shared],
    ],
    'legal' => [
        'welcome' => ['name' => 'ترحيب قانوني', 'name_en' => 'Legal Welcome', 'description' => 'ترحيب بعميل قانوني', 'variables' => $shared],
        'notification' => ['name' => 'إشعار قانوني', 'name_en' => 'Legal Notification', 'description' => 'إشعار مستند أو موعد', 'variables' => $shared],
        'contract' => ['name' => 'عقد قانوني', 'name_en' => 'Legal Contract', 'description' => 'إرسال عقد أو اتفاقية', 'variables' => $contract],
        'marketing' => ['name' => 'تسويق قانوني', 'name_en' => 'Legal Marketing', 'description' => 'عرض خدمة قانونية', 'variables' => $shared],
    ],
];

$templates = [
    'generic_workspace_branded' => [
        'name' => 'الهوية الحالية لمساحة العمل',
        'name_en' => 'Workspace branded (current)',
        'category' => 'generic',
        'description' => 'القالب الحي المستخدم اليوم في رسائل مساحة العمل. لا تغيّر مساره أو سلوكه من هنا.',
        'view' => 'emails.workspace-branded',
        'preview' => null,
        'variables' => [
            'subject',
            'body',
            'companyName',
            'companyDescription',
            'logoUrl',
            'showHeaderLogo',
            'showHeaderCompanyName',
            'headerStyle',
            'headerLinks',
            'primaryColor',
            'secondaryColor',
            'backgroundColor',
            'textColor',
            'showCta',
            'ctaLabel',
            'ctaUrl',
            'footerEmail',
            'footerPhone',
            'footerWebsite',
            'footerAddress',
            'footerCopyright',
            'showFooterCompanyName',
            'showFooterEmail',
            'showFooterPhone',
            'showFooterWebsite',
            'showFooterAddress',
            'showFooterSocial',
            'showFooterCopyright',
            'socialLinks',
        ],
        'status' => 'active',
        'assets' => null,
    ],
];

foreach ($catalog as $category => $items) {
    foreach ($items as $slug => $meta) {
        $key = str_replace('-', '_', $category).'_'.str_replace('-', '_', $slug);

        $templates[$key] = [
            'name' => $meta['name'],
            'name_en' => $meta['name_en'],
            'category' => $category,
            'description' => $meta['description'],
            'view' => 'emails.library.'.$category.'.'.$slug,
            'preview' => 'email-library/'.$category.'/'.$slug.'/preview.png',
            'variables' => $meta['variables'],
            'status' => $meta['status'] ?? 'draft',
            'assets' => 'email-library/'.$category.'/'.$slug,
        ];
    }
}

$templates['construction_welcome']['defaults'] = [
    'primaryColor' => '#0f766e',
    'secondaryColor' => '#14b8a6',
    'backgroundColor' => '#f4f7f9',
    'textColor' => '#1f3146',
];

return [
    'categories' => $categories,
    'templates' => $templates,
];
