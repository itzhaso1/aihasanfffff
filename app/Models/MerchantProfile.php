<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'provider',
    'provider_merchant_id',
    'rejection_reason',
    'metadata',
])]
class MerchantProfile extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const VERIFICATION_NOT_REQUESTED = 'not_requested';

    public const VERIFICATION_DOCUMENTS_REQUIRED = 'documents_required';

    public const VERIFICATION_PENDING_REVIEW = 'pending_review';

    public const VERIFICATION_APPROVED = 'approved';

    public const VERIFICATION_REJECTED = 'rejected';

    public const VERIFICATION_SUSPENDED = 'suspended';

    public const PROVIDER_NOT_STARTED = 'not_started';

    public const PROVIDER_PENDING = 'pending';

    public const PROVIDER_ACTIVE = 'active';

    public const PROVIDER_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MerchantDocument::class);
    }
}
