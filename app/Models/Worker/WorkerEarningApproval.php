<?php

declare(strict_types=1);

namespace App\Models\Worker;

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
    'approved_by_user_id',
    'approved_at',
    'notes',
])]
class WorkerEarningApproval extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (): void {
            throw WorkerEarningsException::periodImmutable();
        });

        static::deleting(function (): void {
            throw WorkerEarningsException::periodImmutable();
        });
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
