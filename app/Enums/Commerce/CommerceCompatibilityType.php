<?php

namespace App\Enums\Commerce;

enum CommerceCompatibilityType: string
{
    case Compatible = 'compatible';
    case Incompatible = 'incompatible';
    case RequiresAdapter = 'requires_adapter';
    case Conditional = 'conditional';
}
