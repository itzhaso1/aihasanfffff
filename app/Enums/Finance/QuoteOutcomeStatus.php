<?php

namespace App\Enums\Finance;

/**
 * Commercial outcome, independent of document status (draft/issued/cancelled)
 * and independent of delivery status (unsent/sent/failed).
 */
enum QuoteOutcomeStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function labelAr(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Accepted => 'مقبول',
            self::Rejected => 'مرفوض',
            self::Expired => 'منتهي',
            self::Converted => 'محوّل إلى فاتورة',
        };
    }
}
