<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'finance_employee_id',
    'user_id',
    'type',
    'title',
    'amount',
    'effective_date',
    'status',
    'notes',
    'payroll_run_id',
    'posted_journal_entry_id',
    'approved_by',
    'approved_at',
    'posted_by',
    'posted_at',
])]
class FinancePayrollAdjustment extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_date' => 'date',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function financeEmployee(): BelongsTo
    {
        return $this->belongsTo(FinanceEmployee::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(FinancePayrollRun::class, 'payroll_run_id');
    }

    public function postedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(FinanceJournalEntry::class, 'posted_journal_entry_id');
    }
}
