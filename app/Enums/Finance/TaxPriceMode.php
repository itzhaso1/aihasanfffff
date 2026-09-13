<?php

namespace App\Enums\Finance;

enum TaxPriceMode: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';

    public function labelAr(): string
    {
        return match ($this) {
            self::Exclusive => 'غير شامل الضريبة',
            self::Inclusive => 'شامل الضريبة',
        };
    }
}
