<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerDisputeReporterType;
use App\Enums\WorkerDisputeStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerDisputeException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'reporter_type',
    'reported_by_user_id',
    'reported_by_platform_admin_id',
    'category',
    'description',
    'status',
    'reviewer_platform_admin_id',
    'review_notes',
    'resolution_summary',
    'opened_at',
    'under_review_at',
    'resolved_at',
    'closed_at',
])]
class WorkerDispute extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $hidden = [
        'reported_by_user_id',
        'reported_by_platform_admin_id',
        'reviewer_platform_admin_id',
        'review_notes',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $dispute): void {
            $dispute->status ??= WorkerDisputeStatus::Open;
            $dispute->opened_at ??= now();
        });

        static::updating(function (self $dispute): void {
            $current = WorkerDisputeStatus::tryFrom(
                (string) $dispute->getRawOriginal('status'),
            );

            if (! $current instanceof WorkerDisputeStatus) {
                throw WorkerDisputeException::invalidStoredStatus(
                    (string) $dispute->getRawOriginal('status'),
                );
            }

            $immutable = [
                'workspace_id',
                'worker_engagement_id',
                'reporter_type',
                'reported_by_user_id',
                'reported_by_platform_admin_id',
                'category',
                'description',
                'opened_at',
            ];

            if (array_intersect(array_keys($dispute->getDirty()), $immutable) !== []) {
                throw WorkerDisputeException::immutable();
            }

            if (! $dispute->isDirty('status')) {
                return;
            }

            $next = $dispute->status;
            if (
                ! $next instanceof WorkerDisputeStatus
                || ! $current->canTransitionTo($next)
            ) {
                throw WorkerDisputeException::invalidTransition($current, $next);
            }
        });

        static::deleting(function (): void {
            throw WorkerDisputeException::deletionForbidden();
        });
    }

    protected function casts(): array
    {
        return [
            'reporter_type' => WorkerDisputeReporterType::class,
            'status' => WorkerDisputeStatus::class,
            'opened_at' => 'datetime',
            'under_review_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function reportedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reported_by_platform_admin_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewer_platform_admin_id');
    }

    public function transitionTo(
        WorkerDisputeStatus $next,
        array $attributes = [],
    ): void {
        $current = $this->status;

        if (! $current instanceof WorkerDisputeStatus) {
            throw WorkerDisputeException::invalidStoredStatus(
                (string) $this->getRawOriginal('status'),
            );
        }

        if (! $current->canTransitionTo($next)) {
            throw WorkerDisputeException::invalidTransition($current, $next);
        }

        $this->forceFill(array_merge(['status' => $next], $attributes))->save();
    }
}
