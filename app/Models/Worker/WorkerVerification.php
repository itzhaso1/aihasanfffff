<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'worker_profile_id',
    'status',
    'submitted_at',
    'reviewed_at',
    'reviewed_by',
    'rejection_reason',
    'review_notes',
])]
class WorkerVerification extends Model
{
    public const STATUS_DOCUMENTS_REQUIRED = 'documents_required';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WorkerVerificationDocument::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(WorkerVerificationIssue::class);
    }
}
