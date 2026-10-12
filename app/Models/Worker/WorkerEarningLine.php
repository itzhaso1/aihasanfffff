<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerEarningLineType;
use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerEarningSourceType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerEarningsException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'worker_earning_period_id',
    'line_type',
    'source_type',
    'source_id',
    'source_reference',
    'amount_minor',
    'currency',
    'description',
    'adjustment_reason',
    'created_by_user_id',
])]
class WorkerEarningLine extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $line): void {
            self::assertPeriodEditable($line);
        });

        static::updating(function (self $line): void {
            self::assertPeriodEditable($line);
        });

        static::deleting(function (self $line): void {
            self::assertPeriodEditable($line);
        });
    }

    protected function casts(): array
    {
        return [
            'line_type' => WorkerEarningLineType::class,
            'source_type' => WorkerEarningSourceType::class,
            'source_id' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(WorkerEarningPeriod::class, 'worker_earning_period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    private static function assertPeriodEditable(self $line): void
    {
        $period = WorkerEarningPeriod::withoutGlobalScopes()
            ->whereKey($line->worker_earning_period_id)
            ->first();

        if ($period === null) {
            throw WorkerEarningsException::lineImmutable();
        }

        if (
            (int) $period->workspace_id !== (int) $line->workspace_id
            || (int) $period->worker_engagement_id !== (int) $line->worker_engagement_id
        ) {
            throw WorkerEarningsException::engagementMismatch();
        }

        if (
            ! $period->status instanceof WorkerEarningPeriodStatus
            || $period->status->isFinalized()
        ) {
            throw WorkerEarningsException::lineImmutable();
        }
    }
}
