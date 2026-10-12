<?php

namespace App\Enums\Finance;

enum TaxPriceMode: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
}
