<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'arabic_name',
    'vat_number',
    'commercial_registration',
    'address',
    'phone',
    'email',
    'opening_balance',
    'payment_terms',
    'status',
    'metadata',
])]
class FinanceSupplier extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class, 'supplier_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'supplier_id');
    }
}
