<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_profile_id',
    'worker_verification_id',
    'document_type_code',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size_bytes',
    'status',
    'rejection_reason',
    'uploaded_by',
    'reviewed_by',
    'reviewed_at',
])]
class WorkerVerificationDocument extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REPLACED = 'replaced';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }
}
