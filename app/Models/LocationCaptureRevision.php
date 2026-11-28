<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'customer_location_id',
    'capture_session_id',
    'conversation_id',
    'customer_id',
    'order_id',
    'event_type',
    'latitude',
    'longitude',
    'accuracy_meters',
    'source',
    'platform',
    'formatted_address',
    'actor_type',
    'actor_id',
    'metadata',
    'created_at',
])]
class LocationCaptureRevision extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(CustomerLocation::class, 'customer_location_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LocationCaptureSession::class, 'capture_session_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
