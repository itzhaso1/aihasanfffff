<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ChatCatalogItemVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'chat_catalog_item_id',
    'name',
    'sku',
    'barcode',
    'price',
    'compare_at_price',
    'sale_price',
    'currency',
    'status',
    'is_visible',
    'sort_order',
    'stock',
    'inventory_policy',
    'is_active',
    'attributes',
])]
class ChatCatalogItemVariant extends WorkspaceScopedModel
{
    /** @use HasFactory<ChatCatalogItemVariantFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'inventory_policy' => ChatCatalogInventoryPolicy::class,
            'attributes' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }

    public function digitalProfile(): HasOne
    {
        return $this->hasOne(ChatCatalogItemDigitalProfile::class, 'chat_catalog_item_variant_id');
    }

    public function digitalPools(): HasMany
    {
        return $this->hasMany(ChatCatalogDigitalInventoryPool::class, 'chat_catalog_item_variant_id');
    }

    public function structuredAttributes(): HasMany
    {
        return $this->hasMany(ChatCatalogItemAttribute::class, 'chat_catalog_item_variant_id');
    }
}
