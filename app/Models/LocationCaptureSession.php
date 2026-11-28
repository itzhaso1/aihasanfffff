<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'conversation_id',
    'customer_id',
    'order_id',
    'customer_location_id',
    'requested_by_message_id',
    'token_hash',
    'token_hint',
    'platform',
    'purpose',
    'status',
    'attempt_count',
    'expires_at',
    'opened_at',
    'confirmed_at',
    'locked_at',
    'submitted_ip_hash',
    'metadata',
])]
class LocationCaptureSession extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'expires_at' => 'datetime',
            'opened_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'locked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customerLocation(): BelongsTo
    {
        return $this->belongsTo(CustomerLocation::class, 'customer_location_id');
    }

    public function requestedByMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'requested_by_message_id');
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || ($this->expires_at !== null && now()->greaterThan($this->expires_at));
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_CONFIRMED || $this->locked_at !== null;
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isLocked() && ! $this->isExpired();
    }
}
