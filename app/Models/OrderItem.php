<?php

namespace App\Models;

use App\Models\Commerce\CommerceInventoryReservation;
use App\Models\Commerce\Seller;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'order_id',
    'commerce_seller_id',
    'chat_catalog_item_id',
    'chat_catalog_item_variant_id',
    'product_id',
    'product_variant_id',
    'pos_menu_item_id',
    'product_name',
    'variant_name',
    'item_type',
    'sku',
    'barcode',
    'seller_display_name',
    'currency',
    'classification',
    'fulfillment_classification',
    'tax_presentation',
    'quantity',
    'unit_price',
    'discount_amount',
    'taxable_amount',
    'tax_rate',
    'tax_amount',
    'total_amount',
    'catalog_snapshot',
    'price_snapshot',
    'tax_snapshot',
    'selected_options',
    'metadata',
])]
class OrderItem extends WorkspaceScopedModel
{
    /** @use HasFactory<OrderItemFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'catalog_snapshot' => 'array',
            'price_snapshot' => 'array',
            'tax_snapshot' => 'array',
            'selected_options' => 'array',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function commerceSeller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function chatCatalogItem(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItem::class, 'chat_catalog_item_id');
    }

    public function chatCatalogVariant(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogItemVariant::class, 'chat_catalog_item_variant_id');
    }

    public function posMenuItem(): BelongsTo
    {
        return $this->belongsTo(PosMenuItem::class, 'pos_menu_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function inventoryReservations(): HasMany
    {
        return $this->hasMany(CommerceInventoryReservation::class);
    }
}
