<?php

return [
    'mailer' => env('CENTRAL_EMAIL_MAILER', env('MAIL_MAILER', 'resend')),
    'default_template' => 'general_notification',
    'default_from_address' => env('EMAIL_FROM', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    'default_from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'HASEM')),

    /*
    |--------------------------------------------------------------------------
    | Centralized email templates
    |--------------------------------------------------------------------------
    |
    | Every email sent by the backend should use one of these template keys.
    | New features can add templates here without changing transport logic.
    |
    */
    'templates' => [
        'welcome' => [
            'view' => 'emails.templates.welcome',
            'subject' => 'مرحبًا بك في HASEM',
        ],
        'email_verification' => [
            'view' => 'emails.templates.email-verification',
            'subject' => 'تأكيد البريد الإلكتروني',
        ],
        'password_reset' => [
            'view' => 'emails.templates.password-reset',
            'subject' => 'إعادة تعيين كلمة المرور',
        ],
        'invoice_email' => [
            'view' => 'emails.templates.invoice-email',
            'subject' => 'إشعار فاتورة',
        ],
        'contract_email' => [
            'view' => 'emails.templates.contract-email',
            'subject' => 'إشعار عقد',
        ],
        'quote_email' => [
            'view' => 'emails.templates.quote-email',
            'subject' => 'عرض سعر',
        ],
        'receipt_email' => [
            'view' => 'emails.templates.receipt-email',
            'subject' => 'إيصال دفع',
        ],
        'invoice_reminder_email' => [
            'view' => 'emails.templates.invoice-reminder-email',
            'subject' => 'تذكير بفاتورة',
        ],
        'payroll_email' => [
            'view' => 'emails.templates.payroll-email',
            'subject' => 'إشعار رواتب',
        ],
        'finance_notification' => [
            'view' => 'emails.templates.finance-notification',
            'subject' => 'تنبيه مالي',
        ],
        'document_email' => [
            'view' => 'emails.templates.document-email',
            'subject' => 'مستند من النظام',
        ],
        'general_notification' => [
            'view' => 'emails.templates.general-notification',
            'subject' => 'إشعار من النظام',
        ],
        'workspace_branded' => [
            'view' => 'emails.templates.workspace-branded',
            'subject' => 'رسالة جديدة',
        ],
    ],
];
