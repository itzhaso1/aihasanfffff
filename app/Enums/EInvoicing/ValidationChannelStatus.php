<?php

namespace App\Enums\EInvoicing;

enum ValidationChannelStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case NotAvailable = 'not_available';
}
