<?php

namespace App\Models;

use App\Models\Commerce\SellerAllocation;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'order_id',
    'billable_type',
    'billable_id',
    'checkout_reference',
    'payment_gateway_id',
    'provider',
    'provider_payment_id',
    'idempotency_key',
    'status',
    'amount',
    'currency',
    'payment_link',
    'provider_payload',
    'payment_context',
    'money_bucket',
    'paid_at',
    'failed_at',
    'failure_reason',
])]
class Payment extends WorkspaceScopedModel
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToWorkspace, HasFactory;

    /** Legacy leftover billable type. Chat payment runtime must not create or settle these rows. */
    public const BILLABLE_FINANCE_INVOICE = 'finance_invoice';

    /** Legacy leftover context string retained for existing money-bucket mapping. */
    public const CONTEXT_MERCHANT_INVOICE = 'merchant_invoice';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_payload' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * Identifies leftover Finance-invoice payment rows in the shared payments table.
     * Chat checkout and webhook settlement ignore these rows (no order_id).
     */
    public function isFinanceInvoiceBillable(): bool
    {
        return $this->billable_type === self::BILLABLE_FINANCE_INVOICE
            && $this->billable_id
            && $this->order_id === null;
    }

    public function sellerAllocations(): HasMany
    {
        return $this->hasMany(SellerAllocation::class);
    }
}
