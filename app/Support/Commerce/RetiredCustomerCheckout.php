<?php

namespace App\Support\Commerce;

use RuntimeException;

/**
 * Customer checkout surfaces that were retired: digital product selling/delivery
 * and payment-link collection. Tables remain; HTTP and domain entry points stop.
 */
final class RetiredCustomerCheckout
{
    public const DIGITAL_PRODUCTS_MESSAGE = 'Digital product selling and delivery are disabled.';

    public const PAYMENT_LINKS_MESSAGE = 'Customer payment links are disabled.';

    public const DIGITAL_PRODUCTS_USER_MESSAGE = 'المنتجات الرقمية وتسليمها عبر الشات ملغاة. احفظ منتجاً عادياً كمرجع لمعلومات الذكاء الاصطناعي.';

    public static function abortDigitalProducts(): never
    {
        abort(410, self::DIGITAL_PRODUCTS_MESSAGE);
    }

    public static function abortPaymentLinks(): never
    {
        abort(410, self::PAYMENT_LINKS_MESSAGE);
    }

    public static function assertPaymentLinksEnabled(): void
    {
        throw new RuntimeException(self::PAYMENT_LINKS_MESSAGE);
    }
}
