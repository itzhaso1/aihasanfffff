<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceCartStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\Order;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'customer_id',
    'commerce_seller_id',
    'currency',
    'status',
    'channel',
    'metadata',
    'expires_at',
    'converted_order_id',
    'conversion_idempotency_key',
])]
class CommerceCart extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_carts';

    protected function casts(): array
    {
        return [
            'status' => CommerceCartStatus::class,
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommerceCartItem::class, 'commerce_cart_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === CommerceCartStatus::Active && ! $this->isExpired();
    }
}
