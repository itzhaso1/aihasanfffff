<?php

namespace App\Models\Contract;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceInvoice;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'customer_id',
    'project_id',
    'contract_number',
    'title',
    'status',
    'value',
    'currency',
    'start_date',
    'end_date',
    'activated_at',
    'closed_at',
    'cancelled_at',
    'terms',
    'notes',
    'company_snapshot',
    'customer_snapshot',
    'pdf_snapshot',
    'metadata',
    'created_by',
])]
class Contract extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'company_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'pdf_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FinanceProject::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContractAttachment::class)->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceInvoice::class)->latest('id');
    }

    public function billingSchedules(): HasMany
    {
        return $this->hasMany(FinanceBillingSchedule::class)->latest('id');
    }
}
