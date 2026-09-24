<?php

namespace App\Enums\ChatCatalog;

enum ChatCatalogInventoryPolicy: string
{
    case NotTracked = 'not_tracked';
    case FiniteStock = 'finite_stock';
    case Unlimited = 'unlimited';
    case Backorder = 'backorder';
    case OnRequest = 'on_request';
    case DigitalPool = 'digital_pool';
    case FileAsset = 'file_asset';
    case ServiceCapacity = 'service_capacity';
}
