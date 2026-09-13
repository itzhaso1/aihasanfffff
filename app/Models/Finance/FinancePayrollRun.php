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
    'period_month',
    'status',
    'total_gross',
    'total_deductions',
    'total_net',
    'processed_by',
])]
class FinancePayrollRun extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinancePayrollItem::class, 'payroll_run_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(FinancePayrollAdjustment::class, 'payroll_run_id');
    }
}
