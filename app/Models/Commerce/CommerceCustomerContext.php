<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'customer_id',
    'session_id',
    'context_key',
    'context_value',
    'data_type',
    'unit',
    'source',
    'confidence',
    'metadata',
])]
class CommerceCustomerContext extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_customer_contexts';

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'source' => CommerceProvenanceSource::class,
            'confidence' => 'float',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
