<?php

namespace App\Enums\Commerce;

enum CommerceDomain: string
{
    case Fashion = 'fashion';
    case Electronics = 'electronics';
    case Automotive = 'automotive';
    case Furniture = 'furniture';
    case Food = 'food';
    case Beauty = 'beauty';
    case Jewelry = 'jewelry';
    case Services = 'services';
    case Subscriptions = 'subscriptions';
    case Digital = 'digital';
    case Education = 'education';
    case Hospitality = 'hospitality';
    case Custom = 'custom';
    case MultiCategory = 'multi_category';
}
