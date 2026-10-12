<?php

namespace App\Services\Subscription;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
use App\Support\Subscription\SubscriptionPaymentVerification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Production/staging stand-in. Never activates a subscription.
 */
class UnconfiguredSubscriptionBillingProvider implements SubscriptionBillingProviderInterface
{
    public function activatePlan(Workspace $workspace, Plan $plan, array $options = []): Subscription
    {
        Log::error('subscription.billing.provider_unconfigured', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'environment' => app()->environment(),
        ]);

        throw UntrustedSubscriptionActivationException::providerUnconfigured();
    }

    public function integrationMode(): string
    {
        return 'unconfigured';
    }

    public function verifySubscriptionWebhook(array $headers, array $payload, ?string $rawBody = null): SubscriptionPaymentVerification
    {
        $eventId = (string) ($payload['event_id'] ?? Str::uuid());

        return SubscriptionPaymentVerification::rejected(
            eventId: $eventId,
            reason: 'billing_provider_unconfigured',
            providerCheckoutId: isset($payload['provider_checkout_id']) ? (string) $payload['provider_checkout_id'] : null,
        );
    }
}
