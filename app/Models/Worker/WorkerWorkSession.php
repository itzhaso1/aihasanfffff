<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerWorkSessionStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerWorkSessionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'status',
    'active_session_key',
    'started_at',
    'ended_at',
    'duration_seconds',
    'started_by_user_id',
    'ended_by_user_id',
])]
class WorkerWorkSession extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $session): void {
            $status = $session->status;

            if (
                ! $status instanceof WorkerWorkSessionStatus
                || $status !== WorkerWorkSessionStatus::Started
            ) {
                throw WorkerWorkSessionException::invalidSessionTransition(
                    WorkerWorkSessionStatus::Ended,
                    $status instanceof WorkerWorkSessionStatus
                        ? $status
                        : WorkerWorkSessionStatus::Ended,
                );
            }

            if (! filled($session->active_session_key)) {
                throw WorkerWorkSessionException::activeSessionKeyRequired();
            }
        });

        static::updating(function (self $session): void {
            $current = WorkerWorkSessionStatus::tryFrom((string) $session->getRawOriginal('status'));

            if ($current === WorkerWorkSessionStatus::Ended) {
                throw WorkerWorkSessionException::historicalSessionImmutable();
            }

            if ($session->isDirty('status')) {
                $next = $session->status;

                if (
                    ! $next instanceof WorkerWorkSessionStatus
                    || ! $current instanceof WorkerWorkSessionStatus
                    || ! $current->canTransitionTo($next)
                ) {
                    throw WorkerWorkSessionException::invalidSessionTransition(
                        $current ?? WorkerWorkSessionStatus::Ended,
                        $next instanceof WorkerWorkSessionStatus
                            ? $next
                            : WorkerWorkSessionStatus::Ended,
                    );
                }

                if (
                    $next === WorkerWorkSessionStatus::Ended
                    && ($session->ended_at === null || $session->duration_seconds === null)
                ) {
                    throw WorkerWorkSessionException::endedSessionRequiresEndData();
                }
            }
        });

        static::deleting(function (): void {
            throw WorkerWorkSessionException::sessionDeletionForbidden();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WorkerWorkSessionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(WorkerWorkSessionSegment::class, 'worker_work_session_id');
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(WorkerActivityEvent::class, 'worker_work_session_id');
    }

    public function transitionTo(WorkerWorkSessionStatus $next): void
    {
        $current = $this->status;

        if (! $current instanceof WorkerWorkSessionStatus || ! $current->canTransitionTo($next)) {
            throw WorkerWorkSessionException::invalidSessionTransition(
                $current instanceof WorkerWorkSessionStatus
                    ? $current
                    : WorkerWorkSessionStatus::Ended,
                $next,
            );
        }

        $this->forceFill(['status' => $next])->save();
    }
}
