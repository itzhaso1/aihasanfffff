<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'return_id',
    'order_item_id',
    'qty',
    'amount',
])]
class PosOrderReturnItem extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function posReturn(): BelongsTo
    {
        return $this->belongsTo(PosOrderReturn::class, 'return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
