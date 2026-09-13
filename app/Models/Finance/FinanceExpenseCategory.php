<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'code',
    'linked_account_id',
])]
class FinanceExpenseCategory extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'linked_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'category_id');
    }
}
