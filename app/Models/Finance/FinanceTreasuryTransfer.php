<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'from_treasury_account_id',
    'to_treasury_account_id',
    'amount',
    'transfer_date',
    'reference',
    'status',
    'journal_entry_id',
    'notes',
    'created_by',
])]
class FinanceTreasuryTransfer extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_date' => 'date',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceTreasuryAccount::class, 'from_treasury_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceTreasuryAccount::class, 'to_treasury_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
