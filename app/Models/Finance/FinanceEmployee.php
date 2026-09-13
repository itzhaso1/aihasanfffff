<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'employee_code',
    'full_name',
    'job_title',
    'basic_salary',
    'hire_date',
    'status',
    'phone',
    'email',
    'address',
    'emergency_contact',
    'notes',
    'metadata',
    'created_by',
])]
class FinanceEmployee extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'hire_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payrollRecords(): HasMany
    {
        return $this->hasMany(FinanceEmployeePayrollRecord::class)->latest('period_start');
    }

    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(FinanceSalaryAdvance::class);
    }

    public function payrollAdjustments(): HasMany
    {
        return $this->hasMany(FinancePayrollAdjustment::class);
    }
}
