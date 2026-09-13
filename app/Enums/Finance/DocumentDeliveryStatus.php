<?php

namespace App\Enums\Finance;

/**
 * Per-send delivery state. Independent from document status (draft/issued/cancelled).
 * Viewed tracking is intentionally not a delivery-record status in Phase C.
 */
enum DocumentDeliveryStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function labelAr(): string
    {
        return match ($this) {
            self::Sending => 'جارٍ الإرسال',
            self::Sent => 'تم الإرسال',
            self::Failed => 'فشل الإرسال',
        };
    }
}
