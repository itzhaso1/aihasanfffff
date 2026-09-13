<?php

namespace App\Enums\EInvoicing;

/**
 * Central catalog of standard vs simplified transaction codes.
 * Do not scatter 0100000 / 0200000 elsewhere.
 */
enum InvoiceTransactionCode: string
{
    case Standard = '0100000';
    case Simplified = '0200000';

    public function isSimplified(): bool
    {
        return $this === self::Simplified;
    }

    public function isStandard(): bool
    {
        return $this === self::Standard;
    }
}
