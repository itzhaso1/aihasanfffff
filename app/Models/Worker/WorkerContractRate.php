<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\PlatformAdmin;
use Database\Factories\WorkerContractRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_profile_id',
    'duration_value',
    'duration_unit',
    'amount_minor',
    'currency',
    'effective_from',
    'effective_until',
    'created_by_platform_admin_id',
    'is_active',
])]
class WorkerContractRate extends Model
{
    /** @use HasFactory<WorkerContractRateFactory> */
    use HasFactory;

    protected static function newFactory(): WorkerContractRateFactory
    {
        return WorkerContractRateFactory::new();
    }

    protected function casts(): array
    {
        return [
            'duration_value' => 'integer',
            'amount_minor' => 'integer',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }
}
