<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'bank_statement_id',
    'posted_date',
    'description',
    'reference',
    'amount',
    'status',
    'suggested_type',
    'suggested_id',
    'suggestion_confidence',
    'suggestion_reason',
    'matched_type',
    'matched_id',
    'matched_by',
    'matched_at',
])]
class FinanceBankStatementLine extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'amount' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(FinanceBankStatement::class, 'bank_statement_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
