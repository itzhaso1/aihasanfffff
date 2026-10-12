<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_profile_id',
    'title',
    'organization_name',
    'started_on',
    'ended_on',
    'is_current',
    'description',
])]
class WorkerWorkExperience extends Model
{
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }
}
