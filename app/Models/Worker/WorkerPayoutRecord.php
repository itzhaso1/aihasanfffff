<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerPayoutStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\PlatformAdmin;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerPayoutException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'worker_profile_id',
    'worker_engagement_id',
    'worker_earning_period_id',
    'amount_minor',
    'currency',
    'status',
    'provider_reference',
    'failure_reason',
    'created_by_platform_admin_id',
    'requested_at',
    'processing_at',
    'paid_at',
    'failed_at',
    'cancelled_at',
])]
class WorkerPayoutRecord extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $payout): void {
            if (
                ! $payout->status instanceof WorkerPayoutStatus
                || $payout->status !== WorkerPayoutStatus::Pending
            ) {
                throw WorkerPayoutException::invalidStoredStatus(
                    (string) $payout->getAttribute('status'),
                );
            }

            if ((int) $payout->amount_minor <= 0) {
                throw WorkerPayoutException::invalidAmount();
            }
        });

        static::updating(function (self $payout): void {
            $current = WorkerPayoutStatus::tryFrom(
                (string) $payout->getRawOriginal('status'),
            );

            if (! $current instanceof WorkerPayoutStatus) {
                throw WorkerPayoutException::invalidStoredStatus(
                    (string) $payout->getRawOriginal('status'),
                );
            }

            if ($current->isTerminal()) {
                throw WorkerPayoutException::historicalPayoutImmutable();
            }

            if (! $payout->isDirty('status')) {
                throw WorkerPayoutException::historicalPayoutImmutable();
            }

            $next = $payout->status;
            if (
                ! $next instanceof WorkerPayoutStatus
                || ! $current->canTransitionTo($next)
            ) {
                throw WorkerPayoutException::invalidTransition(
                    $current,
                    $next instanceof WorkerPayoutStatus
                        ? $next
                        : WorkerPayoutStatus::Pending,
                );
            }
        });

        static::deleting(function (): void {
            throw WorkerPayoutException::historicalPayoutImmutable();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WorkerPayoutStatus::class,
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(WorkerEarningPeriod::class, 'worker_earning_period_id');
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function transitionTo(
        WorkerPayoutStatus $next,
        array $attributes = [],
    ): void {
        $current = $this->status;

        if (! $current instanceof WorkerPayoutStatus) {
            throw WorkerPayoutException::invalidStoredStatus(
                (string) $this->getRawOriginal('status'),
            );
        }

        if (! $current->canTransitionTo($next)) {
            throw WorkerPayoutException::invalidTransition($current, $next);
        }

        $this->forceFill(array_merge(['status' => $next], $attributes))->save();
    }
}
