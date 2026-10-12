<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_profile_id',
    'language_code',
])]
class WorkerProfileLanguage extends Model
{
    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }
}
