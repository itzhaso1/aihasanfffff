<?php

namespace App\Support\Subscription;

final class SubscriptionPaymentVerification
{
    public function __construct(
        public readonly bool $verified,
        public readonly string $eventId,
        public readonly ?string $providerCheckoutId,
        public readonly ?string $outcome,
        public readonly ?string $paymentReference,
        public readonly ?string $reason = null,
        public readonly ?string $payloadAmount = null,
        public readonly ?string $payloadCurrency = null,
    ) {}

    public static function rejected(string $eventId, string $reason, ?string $providerCheckoutId = null, ?string $outcome = null): self
    {
        return new self(
            verified: false,
            eventId: $eventId,
            providerCheckoutId: $providerCheckoutId,
            outcome: $outcome,
            paymentReference: null,
            reason: $reason,
        );
    }

    public function isPaid(): bool
    {
        return $this->verified && $this->outcome === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->verified && $this->outcome === 'failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function redacted(): array
    {
        return [
            'event_id' => $this->eventId,
            'provider_checkout_id' => $this->providerCheckoutId,
            'outcome' => $this->outcome,
            'verified' => $this->verified,
            'reason' => $this->reason,
        ];
    }
}
