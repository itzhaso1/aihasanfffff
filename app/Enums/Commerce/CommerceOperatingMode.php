<?php

namespace App\Enums\Commerce;

/**
 * How a workspace participates in Commerce (domain flag only — no checkout behavior in foundation phase).
 */
enum CommerceOperatingMode: string
{
    case IndependentStore = 'independent_store';
    case Marketplace = 'marketplace';
    case Hybrid = 'hybrid';
}
