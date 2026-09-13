<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'type',
    'account_number',
    'iban',
    'bank_name',
    'currency',
    'opening_balance',
    'current_balance',
    'linked_finance_account_id',
    'is_active',
    'metadata',
])]
class FinanceTreasuryAccount extends WorkspaceScopedModel
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'linked_finance_account_id');
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(FinanceInvoicePayment::class, 'treasury_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'treasury_account_id');
    }

    public function salaryAdvanceRepayments(): HasMany
    {
        return $this->hasMany(FinanceSalaryAdvanceRepayment::class, 'treasury_account_id');
    }
}
