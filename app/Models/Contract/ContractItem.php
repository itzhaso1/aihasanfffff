<?php

namespace App\Models\Contract;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Product;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'contract_id',
    'product_id',
    'sort_order',
    'title',
    'item_kind',
    'unit',
    'description',
    'quantity',
    'unit_price',
    'discount',
    'discount_type',
    'tax_rate',
    'tax_type',
    'taxable_amount',
    'tax_amount',
    'total',
    'metadata',
])]
class ContractItem extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
