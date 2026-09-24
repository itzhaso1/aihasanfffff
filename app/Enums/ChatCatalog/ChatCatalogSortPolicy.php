<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogSortPolicy: string
{
    case FeaturedFirst = 'featured_first';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case PriceLowHigh = 'price_low_high';
    case PriceHighLow = 'price_high_low';
    case NameAsc = 'name_asc';
    case NameDesc = 'name_desc';
    case AvailabilityFirst = 'availability_first';
    case Manual = 'manual';
}
