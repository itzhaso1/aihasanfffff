<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'salary_advance_id',
    'treasury_account_id',
    'payment_date',
    'amount',
    'method',
    'status',
    'notes',
    'posted_journal_entry_id',
    'created_by',
])]
class FinanceSalaryAdvanceRepayment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function salaryAdvance(): BelongsTo
    {
        return $this->belongsTo(FinanceSalaryAdvance::class, 'salary_advance_id');
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceTreasuryAccount::class, 'treasury_account_id');
    }

    public function postedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(FinanceJournalEntry::class, 'posted_journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
