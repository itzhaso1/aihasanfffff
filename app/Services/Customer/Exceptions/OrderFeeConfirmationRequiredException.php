<?php

declare(strict_types=1);

namespace App\Services\Customer\Exceptions;

use RuntimeException;

final class OrderFeeConfirmationRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $proposedAddress,
        public readonly string $oldShippingAmount,
        public readonly string $newShippingAmount,
        public readonly string $newTotalAmount,
        public readonly string $currency = 'SAR',
        public readonly bool $outOfZone = false,
        public readonly ?string $zoneName = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : ($outOfZone
            ? 'العنوان الجديد خارج نطاق التوصيل المتاح حالياً.'
            : 'تغيير العنوان يؤثر على رسوم التوصيل وإجمالي الطلب، يرجى أخذ موافقة العميل أولاً.'));
    }
}
