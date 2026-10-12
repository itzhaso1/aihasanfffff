<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceSettlementStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\WorkspaceScopedModel;
use Database\Factories\Commerce\SellerAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'order_id',
    'payment_id',
    'order_item_id',
    'commerce_seller_id',
    'gross_amount',
    'platform_fee_amount',
    'fee_type',
    'fee_metadata',
    'seller_net_amount',
    'currency',
    'settlement_status',
    'provider_settlement_reference',
    'metadata',
])]
class SellerAllocation extends WorkspaceScopedModel
{
    /** @use HasFactory<SellerAllocationFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $table = 'commerce_seller_allocations';

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'platform_fee_amount' => 'decimal:2',
            'seller_net_amount' => 'decimal:2',
            'settlement_status' => CommerceSettlementStatus::class,
            'fee_metadata' => 'array',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }
}
