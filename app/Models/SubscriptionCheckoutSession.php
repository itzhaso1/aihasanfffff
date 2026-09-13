<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'plan_id',
    'activated_subscription_id',
    'checkout_status',
    'payment_status',
    'subscription_status',
    'payment_provider',
    'provider_checkout_id',
    'payment_reference',
    'amount',
    'currency',
    'metadata',
    'expires_at',
    'paid_at',
    'failed_at',
    'failure_reason',
])]
class SubscriptionCheckoutSession extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function activatedSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'activated_subscription_id');
    }
}
