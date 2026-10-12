<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerEarningPeriodStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerEarningsException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'status',
    'starts_at',
    'ends_at',
    'created_by_user_id',
    'approved_by_user_id',
    'approved_at',
    'paid_at',
])]
class WorkerEarningPeriod extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $period): void {
            $current = WorkerEarningPeriodStatus::tryFrom(
                (string) $period->getRawOriginal('status'),
            );

            if (! $current instanceof WorkerEarningPeriodStatus) {
                throw WorkerEarningsException::invalidPeriodStatus(
                    (string) $period->getRawOriginal('status'),
                );
            }

            if ($current->isFinalized()) {
                $dirty = array_keys($period->getDirty());
                $allowed = $current === WorkerEarningPeriodStatus::Approved
                    ? ['status', 'paid_at']
                    : [];

                if (array_diff($dirty, $allowed) !== []) {
                    throw WorkerEarningsException::periodImmutable();
                }
            }

            if (! $period->isDirty('status')) {
                return;
            }

            $next = $period->status;
            if (
                ! $next instanceof WorkerEarningPeriodStatus
                || ! $current->canTransitionTo($next)
            ) {
                throw WorkerEarningsException::invalidPeriodTransition(
                    $current,
                    $next instanceof WorkerEarningPeriodStatus
                        ? $next
                        : WorkerEarningPeriodStatus::Draft,
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WorkerEarningPeriodStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(WorkerEarningLine::class, 'worker_earning_period_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkerEarningApproval::class, 'worker_earning_period_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(WorkerPayoutRecord::class, 'worker_earning_period_id');
    }

    public function transitionTo(
        WorkerEarningPeriodStatus $next,
        array $attributes = [],
    ): void {
        $current = $this->status;

        if (! $current instanceof WorkerEarningPeriodStatus) {
            throw WorkerEarningsException::invalidPeriodStatus(
                (string) $this->getRawOriginal('status'),
            );
        }

        if (! $current->canTransitionTo($next)) {
            throw WorkerEarningsException::invalidPeriodTransition($current, $next);
        }

        $this->forceFill(array_merge(['status' => $next], $attributes))->save();
    }
}
