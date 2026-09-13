<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Finance\FinanceInvoiceItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'category_id',
    'name',
    'slug',
    'description',
    'sku',
    'barcode',
    'price',
    'sale_price',
    'cost_price',
    'vat_rate',
    'currency',
    'stock',
    'inventory_tracking',
    'status',
    'product_kind',
    'digital_type',
    'download_limit',
    'digital_asset_disk',
    'digital_asset_path',
    'brand',
    'weight',
    'images',
    'attributes',
])]
class Product extends WorkspaceScopedModel
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'weight' => 'decimal:3',
            'inventory_tracking' => 'boolean',
            'download_limit' => 'integer',
            'images' => 'array',
            'attributes' => 'array',
        ];
    }

    public function isDigital(): bool
    {
        return $this->product_kind === 'digital';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function financeInvoiceItems(): HasMany
    {
        return $this->hasMany(FinanceInvoiceItem::class);
    }
}
