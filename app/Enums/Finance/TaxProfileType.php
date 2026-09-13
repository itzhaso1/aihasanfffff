<?php

namespace App\Enums\Finance;

enum TaxProfileType: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';

    public function labelAr(): string
    {
        return match ($this) {
            self::Standard => 'قياسية',
            self::ZeroRated => 'صفرية',
            self::Exempt => 'معفاة',
            self::OutOfScope => 'خارج النطاق',
        };
    }

    public function chargesVat(): bool
    {
        return $this === self::Standard;
    }
}
