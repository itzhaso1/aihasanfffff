<?php

namespace App\Models;

use App\Enums\Commerce\CommerceOrderStatus;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceInventoryReservation;
use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerAllocation;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Finance\FinanceInvoice;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'customer_id',
    'commerce_seller_id',
    'commerce_cart_id',
    'dining_table_id',
    'table_session_id',
    'finance_invoice_id',
    'pos_cashier_invoice_id',
    'order_number',
    'client_reference',
    'source',
    'order_type',
    'commerce_status',
    'commerce_channel',
    'status',
    'pos_status',
    'payment_status',
    'fulfillment_status',
    'shipping_status',
    'currency',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'tax_rate',
    'shipping_amount',
    'total_amount',
    'payment_link',
    'notes',
    'metadata',
    'placed_at',
    'cancelled_at',
])]
class Order extends WorkspaceScopedModel
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const ORDER_TYPE_TABLE = 'table';

    public const ORDER_TYPE_TAKEAWAY = 'takeaway';

    public const ORDER_TYPE_DELIVERY = 'delivery';

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'commerce_status' => CommerceOrderStatus::class,
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Optional primary commerce seller (multi-seller lines use order_items.commerce_seller_id).
     */
    public function commerceSeller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }

    public function commerceCart(): BelongsTo
    {
        return $this->belongsTo(CommerceCart::class, 'commerce_cart_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function financeInvoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'finance_invoice_id');
    }

    public function posCashierInvoice(): BelongsTo
    {
        return $this->belongsTo(PosCashierInvoice::class, 'pos_cashier_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sellerAllocations(): HasMany
    {
        return $this->hasMany(SellerAllocation::class);
    }

    public function inventoryReservations(): HasMany
    {
        return $this->hasMany(CommerceInventoryReservation::class);
    }

    public function posReturns(): HasMany
    {
        return $this->hasMany(PosOrderReturn::class);
    }
}
