<?php

namespace App\Support\Chat;

final class ChatAiRole
{
    public const SALES_AGENT = 'sales_agent';

    public const CUSTOMER_SUPPORT = 'customer_support';

    public const BOOKING_AGENT = 'booking_agent';

    public const RECEPTIONIST = 'receptionist';

    public const PRODUCT_ADVISOR = 'product_advisor';

    public const GENERAL = 'general_business_assistant';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SALES_AGENT,
            self::CUSTOMER_SUPPORT,
            self::BOOKING_AGENT,
            self::RECEPTIONIST,
            self::PRODUCT_ADVISOR,
            self::GENERAL,
        ];
    }

    public static function normalize(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));

        return in_array($normalized, self::all(), true) ? $normalized : self::GENERAL;
    }

    public static function label(string $role): string
    {
        return match (self::normalize($role)) {
            self::SALES_AGENT => 'مندوب مبيعات',
            self::CUSTOMER_SUPPORT => 'دعم العملاء',
            self::BOOKING_AGENT => 'منسق حجوزات',
            self::RECEPTIONIST => 'موظف استقبال',
            self::PRODUCT_ADVISOR => 'مستشار منتجات',
            default => 'مساعد أعمال عام',
        };
    }

    public static function instructions(string $role): string
    {
        return match (self::normalize($role)) {
            self::SALES_AGENT => 'دورك مندوب مبيعات. ساعد العميل في الاختيار واعرض الأسعار والميزات فقط من المنتجات والمخزون. لا تبع منتجات غير موجودة.',
            self::CUSTOMER_SUPPORT => 'دورك دعم عملاء. أجب عن السياسات والاستفسارات. لا تتصرف كبائع ولا تقترح منتجات غير موجودة في المنتجات والمخزون.',
            self::BOOKING_AGENT => 'دورك منسق حجوزات. أكد المواعيد فقط إذا وُجدت في المعلومات التجارية. لا تختلق أوقاتًا أو أسعارًا.',
            self::RECEPTIONIST => 'دورك موظف استقبال. وجّه العميل باختصار. لا تخترع خدمات أو أسعارًا.',
            self::PRODUCT_ADVISOR => 'دورك مستشار منتجات. قارن المنتجات الموجودة في المنتجات والمخزون فقط. إذا لم يوجد تطابق قل إنك لا تملك معلومة مؤكدة.',
            default => 'دورك مساعد أعمال عام. أجب من سياق المحادثة والمنتجات والمخزون والمعلومات التجارية فقط.',
        };
    }
}
