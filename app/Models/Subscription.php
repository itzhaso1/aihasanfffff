<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'plan_id',
    'status',
    'starts_at',
    'trial_ends_at',
    'current_period_start',
    'current_period_end',
    'ends_at',
    'cancelled_at',
    'paused_at',
    'grace_ends_at',
    'failed_payment_count',
    'provider',
    'provider_customer_id',
    'provider_subscription_id',
    'metadata',
])]
class Subscription extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paused_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'failed_payment_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
