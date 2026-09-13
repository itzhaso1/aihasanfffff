<?php

namespace App\Models\Crm;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'customer_id',
    'name',
    'company_name',
    'email',
    'phone',
    'source',
    'status',
    'estimated_value',
    'currency',
    'notes',
    'converted_at',
])]
class CrmLead extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
