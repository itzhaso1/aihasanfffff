<?php

namespace App\Enums\Finance;

enum TaxDocumentSubtype: string
{
    case Standard = 'standard';
    case Simplified = 'simplified';
}
