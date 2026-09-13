<?php

namespace App\Models\Finance;

use App\Enums\Finance\FinanceDocumentType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'payment_id',
    'invoice_id',
    'customer_id',
    'receipt_number',
    'currency',
    'payment_date',
    'method',
    'reference',
    'amount',
    'status',
    'voided_at',
    'voided_by',
    'created_by',
])]
class FinanceReceipt extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoicePayment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FinanceDocumentDelivery::class, 'document_id')
            ->where('document_type', FinanceDocumentType::Receipt->value)
            ->latest('id');
    }

    public function isPosted(): bool
    {
        return (string) $this->status === self::STATUS_POSTED;
    }

    public function isVoided(): bool
    {
        return (string) $this->status === self::STATUS_VOIDED;
    }
}
