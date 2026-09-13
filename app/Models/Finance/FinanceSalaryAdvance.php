<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'finance_employee_id',
    'user_id',
    'amount',
    'remaining_amount',
    'issued_at',
    'status',
    'type',
    'payment_method',
    'notes',
])]
class FinanceSalaryAdvance extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'issued_at' => 'date',
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

    public function repayments(): HasMany
    {
        return $this->hasMany(FinanceSalaryAdvanceRepayment::class, 'salary_advance_id');
    }
}
