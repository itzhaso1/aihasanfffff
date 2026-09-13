<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'pos_item_category_id',
    'product_id',
    'name',
    'sku',
    'barcode',
    'item_type',
    'size_label',
    'description',
    'price',
    'currency',
    'image_path',
    'is_active',
    'sort_order',
])]
class PosMenuItem extends WorkspaceScopedModel
{
    /** @use HasFactory<\Database\Factories\PosMenuItemFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PosItemCategory::class, 'pos_item_category_id');
    }

    /**
     * Optional link to catalog Product for inventory sync.
     * When null, PosOrderService skips inventory adjustments.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'pos_menu_item_id');
    }
}
