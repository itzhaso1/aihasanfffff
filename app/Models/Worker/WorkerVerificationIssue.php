<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_profile_id',
    'worker_verification_id',
    'target_type',
    'target_id',
    'field_key',
    'reason',
    'status',
    'resolved_at',
])]
class WorkerVerificationIssue extends Model
{
    public const TARGET_PROFILE = 'profile';

    public const TARGET_SKILLS = 'skills';

    public const TARGET_LANGUAGES = 'languages';

    public const TARGET_EXPERIENCE = 'experience';

    public const TARGET_IDENTITY = 'identity';

    public const TARGET_DOCUMENT = 'document';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(WorkerVerification::class, 'worker_verification_id');
    }
}
