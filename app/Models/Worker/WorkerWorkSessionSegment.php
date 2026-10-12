<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerWorkSessionSegmentType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerWorkSessionException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'worker_work_session_id',
    'segment_type',
    'started_at',
    'ended_at',
    'duration_seconds',
])]
class WorkerWorkSessionSegment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $segment): void {
            if (! $segment->segment_type instanceof WorkerWorkSessionSegmentType) {
                throw WorkerWorkSessionException::segmentTypeRequired();
            }

            if ($segment->ended_at !== null || $segment->duration_seconds !== null) {
                throw WorkerWorkSessionException::segmentEndInvalid();
            }
        });

        static::updating(function (self $segment): void {
            if ($segment->getRawOriginal('ended_at') !== null) {
                throw WorkerWorkSessionException::historicalSessionImmutable();
            }

            $originalType = WorkerWorkSessionSegmentType::tryFrom(
                (string) $segment->getRawOriginal('segment_type'),
            );

            if (
                $segment->isDirty('segment_type')
                && (
                    ! $originalType instanceof WorkerWorkSessionSegmentType
                    || $segment->segment_type !== $originalType
                )
            ) {
                throw WorkerWorkSessionException::invalidSegmentTransition(
                    $originalType ?? WorkerWorkSessionSegmentType::Active,
                    $segment->segment_type instanceof WorkerWorkSessionSegmentType
                        ? $segment->segment_type
                        : WorkerWorkSessionSegmentType::Active,
                );
            }

            if (
                $segment->ended_at !== null
                && (
                    $segment->duration_seconds === null
                    || $segment->started_at === null
                    || $segment->ended_at->lt($segment->started_at)
                )
            ) {
                throw WorkerWorkSessionException::segmentEndInvalid();
            }
        });

        static::deleting(function (): void {
            throw WorkerWorkSessionException::segmentDeletionForbidden();
        });
    }

    protected function casts(): array
    {
        return [
            'segment_type' => WorkerWorkSessionSegmentType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkerWorkSession::class, 'worker_work_session_id');
    }

    public function closeAt(CarbonInterface $endedAt): void
    {
        if ($this->ended_at !== null) {
            throw WorkerWorkSessionException::historicalSessionImmutable();
        }

        if ($this->started_at === null || $endedAt->lt($this->started_at)) {
            throw WorkerWorkSessionException::segmentEndInvalid();
        }

        $this->forceFill([
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, $endedAt->getTimestamp() - $this->started_at->getTimestamp()),
        ])->save();
    }
}
