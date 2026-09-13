<?php

namespace App\Support\Subscription;

use App\Models\Subscription;
use InvalidArgumentException;

/**
 * Allowed SaaS subscription transitions. Extracted from current service
 * methods, then tightened so past_due cannot linger forever.
 */
final class SubscriptionStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        Subscription::STATUS_TRIALING => [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_CANCELLED,
            Subscription::STATUS_EXPIRED,
        ],
        Subscription::STATUS_ACTIVE => [
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_PAUSED,
            Subscription::STATUS_CANCELLED,
            Subscription::STATUS_EXPIRED,
        ],
        Subscription::STATUS_PAST_DUE => [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_CANCELLED,
            Subscription::STATUS_EXPIRED,
        ],
        Subscription::STATUS_PAUSED => [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_CANCELLED,
            Subscription::STATUS_EXPIRED,
        ],
        Subscription::STATUS_CANCELLED => [],
        Subscription::STATUS_EXPIRED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidArgumentException("Subscription status cannot move from {$from} to {$to}.");
        }
    }
}
