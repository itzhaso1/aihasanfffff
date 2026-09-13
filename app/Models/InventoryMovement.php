<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'product_id',
    'product_variant_id',
    'type',
    'quantity',
    'before_quantity',
    'after_quantity',
    'reference_type',
    'reference_id',
    'user_id',
    'notes',
])]
class InventoryMovement extends WorkspaceScopedModel
{
    /** @use HasFactory<\Database\Factories\InventoryMovementFactory> */
    use BelongsToWorkspace, HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
