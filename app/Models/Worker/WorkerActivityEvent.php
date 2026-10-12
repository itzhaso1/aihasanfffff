<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerActivityEventType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerActivityEventException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'worker_profile_id',
    'actor_user_id',
    'worker_work_session_id',
    'event_type',
    'subject_type',
    'subject_id',
    'occurred_at',
    'payload',
])]
class WorkerActivityEvent extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (): void {
            throw WorkerActivityEventException::historicalEventImmutable();
        });

        static::deleting(function (): void {
            throw WorkerActivityEventException::historicalEventImmutable();
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => WorkerActivityEventType::class,
            'subject_id' => 'integer',
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class, 'worker_profile_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkerWorkSession::class, 'worker_work_session_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
