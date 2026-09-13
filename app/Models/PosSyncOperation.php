<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'device_id',
    'operation_uuid',
    'type',
    'status',
    'entity_type',
    'entity_id',
    'request_payload',
    'result_payload',
    'last_error',
    'attempts',
    'processed_at',
])]
class PosSyncOperation extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SYNCING = 'syncing';

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'result_payload' => 'array',
            'processed_at' => 'datetime',
            'entity_id' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
