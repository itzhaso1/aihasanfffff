<?php

namespace App\Services\Subscription;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
use App\Support\Subscription\SubscriptionBillingGuard;
use App\Support\Subscription\SubscriptionPaymentVerification;
use App\Support\Webhooks\WebhookCredential;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local billing provider — no Stripe/HyperPay charge.
 * Allowed only in local/testing. Never honor a client-supplied provider name.
 *
 * integration_mode = "local"
 */
class LocalSubscriptionBillingProvider implements SubscriptionBillingProviderInterface
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function activatePlan(Workspace $workspace, Plan $plan, array $options = []): Subscription
    {
        if (! SubscriptionBillingGuard::localProviderAllowed()) {
            Log::error('subscription.billing.local_provider_blocked', [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'environment' => app()->environment(),
            ]);

            throw UntrustedSubscriptionActivationException::localProviderBlocked();
        }

        $subscription = $this->subscriptions->activatePlan($workspace, $plan);

        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $metadata['integration_mode'] = $this->integrationMode();
        $metadata['activated_via'] = 'local_subscription_billing_provider';
        if ($options !== []) {
            $metadata['options'] = $options;
        }

        $subscription->forceFill([
            'provider' => $subscription->provider ?: 'local',
            'metadata' => $metadata,
        ])->save();

        return $subscription->refresh();
    }

    public function integrationMode(): string
    {
        return 'local';
    }

    public function verifySubscriptionWebhook(array $headers, array $payload, ?string $rawBody = null): SubscriptionPaymentVerification
    {
        $eventId = (string) ($payload['event_id'] ?? Str::uuid());
        $providerCheckoutId = isset($payload['provider_checkout_id']) ? (string) $payload['provider_checkout_id'] : null;
        $outcome = isset($payload['status']) ? strtolower((string) $payload['status']) : null;

        if (! SubscriptionBillingGuard::localProviderAllowed()) {
            return SubscriptionPaymentVerification::rejected($eventId, 'local_provider_blocked', $providerCheckoutId, $outcome);
        }

        $secret = WebhookCredential::configured(config('subscription.webhook_secret'));
        if ($secret === null) {
            return SubscriptionPaymentVerification::rejected($eventId, 'webhook_secret_missing', $providerCheckoutId, $outcome);
        }

        $timestamp = (int) ($headers['x-webhook-timestamp'] ?? $headers['X-Webhook-Timestamp'] ?? 0);
        $signature = (string) ($headers['x-webhook-signature'] ?? $headers['X-Webhook-Signature'] ?? '');
        $tolerance = (int) config('subscription.webhook_tolerance_seconds', 300);

        if ($timestamp <= 0 || abs(time() - $timestamp) > $tolerance) {
            return SubscriptionPaymentVerification::rejected($eventId, 'webhook_timestamp_invalid', $providerCheckoutId, $outcome);
        }

        $rawBody = is_string($rawBody) ? $rawBody : (string) json_encode($payload);
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            return SubscriptionPaymentVerification::rejected($eventId, 'webhook_signature_mismatch', $providerCheckoutId, $outcome);
        }

        $allowedOutcomes = ['paid', 'failed'];
        if (! in_array($outcome, $allowedOutcomes, true)) {
            return SubscriptionPaymentVerification::rejected($eventId, 'webhook_outcome_invalid', $providerCheckoutId, $outcome);
        }

        return new SubscriptionPaymentVerification(
            verified: true,
            eventId: $eventId,
            providerCheckoutId: $providerCheckoutId,
            outcome: $outcome,
            paymentReference: isset($payload['payment_reference']) ? (string) $payload['payment_reference'] : null,
            payloadAmount: isset($payload['amount']) ? (string) $payload['amount'] : null,
            payloadCurrency: isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null,
        );
    }
}
