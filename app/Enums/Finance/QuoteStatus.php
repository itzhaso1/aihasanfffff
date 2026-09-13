<?php

namespace App\Enums\Finance;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Issued => 'صادر',
            self::Cancelled => 'ملغى',
        };
    }

    public function isLocked(): bool
    {
        return $this !== self::Draft;
    }
}
