<?php

namespace App\Services\Commerce;

use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Commerce\CommerceCartItem;
use App\Models\Commerce\Seller;

final readonly class CommerceValidatedCartLine
{
    public function __construct(
        public CommerceCartItem $cartItem,
        public ChatCatalogItem $item,
        public ?ChatCatalogItemVariant $variant,
        public Seller $seller,
        public CommercePriceResolution $price,
        public string $inventoryPolicy,
    ) {}
}
