<?php

namespace App\Enums\Commerce;

enum CommerceSellerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
