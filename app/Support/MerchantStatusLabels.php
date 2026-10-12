<?php

namespace App\Support;

final class MerchantStatusLabels
{
    /**
     * @return array<string, string>
     */
    public static function verification(): array
    {
        return [
            'not_requested' => 'غير موثق',
            'documents_required' => 'يلزم رفع مستندات',
            'pending_review' => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            'suspended' => 'معلّق',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function provider(): array
    {
        return [
            'not_started' => 'لم يبدأ',
            'pending' => 'قيد التفعيل',
            'active' => 'نشط',
            'failed' => 'فشل',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function document(): array
    {
        return [
            'submitted' => 'مُرسل',
            'uploaded' => 'مرفوع',
            'pending_review' => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            'replaced' => 'استُبدل',
        ];
    }

    public static function verificationLabel(?string $status): string
    {
        return self::verification()[$status ?? ''] ?? ($status ?: '—');
    }

    public static function providerLabel(?string $status): string
    {
        return self::provider()[$status ?? ''] ?? ($status ?: '—');
    }

    public static function documentLabel(?string $status): string
    {
        return self::document()[$status ?? ''] ?? ($status ?: '—');
    }
}
