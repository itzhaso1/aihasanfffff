<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'plan_id',
    'subscription_id',
    'checkout_session_id',
    'purpose',
    'status',
    'amount',
    'currency',
    'payment_provider',
    'provider_checkout_id',
    'provider_event_id',
    'payment_reference',
    'period_key',
    'expires_at',
    'verified_at',
    'failed_at',
    'failure_reason',
    'metadata',
])]
class SubscriptionBillingAttempt extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const PURPOSE_CHECKOUT = 'checkout';

    public const PURPOSE_RENEWAL = 'renewal';

    public const PURPOSE_DUNNING = 'dunning';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCheckoutSession::class, 'checkout_session_id');
    }
}
