<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'booking_id',
    'event_type',
    'actor_type',
    'actor_id',
    'previous_status',
    'new_status',
    'previous_starts_at',
    'new_starts_at',
    'payload',
    'created_at',
])]
class BookingRevision extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'actor_id' => 'integer',
            'previous_starts_at' => 'datetime',
            'new_starts_at' => 'datetime',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
