<?php

namespace App\Models\Commerce;

use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'commerce_cart_id',
    'chat_catalog_item_id',
    'chat_catalog_item_variant_id',
    'commerce_seller_id',
    'quantity',
    'unit_price',
    'currency',
    'price_snapshot',
    'selected_options',
    'metadata',
])]
class CommerceCartItem extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_cart_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'price_snapshot' => 'array',
            'selected_options' => 'array',
            'metadata' => 'array',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(CommerceCart::class, 'commerce_cart_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItemVariant::class, 'chat_catalog_item_variant_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }
}
