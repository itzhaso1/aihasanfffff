<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_engagement_id',
    'capability_key',
    'allowed',
])]
class WorkerEngagementPermission extends Model
{
    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function capabilityDefinition(): BelongsTo
    {
        return $this->belongsTo(
            WorkforceCapabilityDefinition::class,
            'capability_key',
            'capability_key',
        );
    }
}
