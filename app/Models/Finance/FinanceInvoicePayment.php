<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'workspace_id',
    'invoice_id',
    'treasury_account_id',
    'payment_date',
    'method',
    'reference',
    'amount',
    'status',
    'notes',
    'created_by',
    'reversed_at',
    'reversed_by',
    'reversal_reason',
])]
class FinanceInvoicePayment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceTreasuryAccount::class, 'treasury_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(FinanceReceipt::class, 'payment_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isPosted(): bool
    {
        $status = (string) ($this->status ?? self::STATUS_POSTED);

        return $status === self::STATUS_POSTED || $status === '';
    }
}
