<?php

namespace App\Enums\Commerce;

enum CommerceCartStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
