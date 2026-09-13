<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'customer_id',
    'contract_id',
    'title',
    'status',
    'frequency',
    'interval_count',
    'total_occurrences',
    'generated_count',
    'amount',
    'currency',
    'start_date',
    'end_date',
    'next_run_on',
    'auto_issue',
    'invoice_type',
    'notes',
    'item_snapshot',
    'metadata',
    'created_by',
])]
class FinanceBillingSchedule extends WorkspaceScopedModel
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'interval_count' => 'integer',
            'total_occurrences' => 'integer',
            'generated_count' => 'integer',
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_run_on' => 'date',
            'auto_issue' => 'boolean',
            'item_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class, 'billing_schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
