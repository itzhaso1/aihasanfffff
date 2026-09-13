<?php

namespace App\Services\Subscription;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Models\Workspace;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
use App\Support\Subscription\HyperPayRemoteCheckoutResult;
use App\Support\Subscription\SubscriptionBillingGuard;
use App\Support\Subscription\SubscriptionPaymentVerification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HyperPay subscription adapter.
 *
 * Official COPYandPAY / charge / webhook / 3DS contract is not in this
 * repository. This class refuses remote checkout creation, webhook
 * verification, and activation rather than inventing API calls.
 */
class HyperPaySubscriptionBillingProvider implements SubscriptionBillingProviderInterface
{
    public function activatePlan(Workspace $workspace, Plan $plan, array $options = []): Subscription
    {
        Log::error('subscription.billing.hyperpay_not_configured', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);

        throw UntrustedSubscriptionActivationException::providerUnconfigured();
    }

    public function integrationMode(): string
    {
        return 'hyperpay_pending';
    }

    public function prepareRemoteCheckout(SubscriptionCheckoutSession $session): HyperPayRemoteCheckoutResult
    {
        $merchantReference = (string) data_get($session->metadata, 'merchant_reference');
        if ($merchantReference === '') {
            $merchantReference = 'subref_'.$session->id.'_'.Str::lower(Str::random(12));
        }

        Log::info('subscription.billing.hyperpay_remote_checkout_blocked', [
            'checkout_session_id' => $session->id,
            'workspace_id' => $session->workspace_id,
            'plan_id' => $session->plan_id,
            'reason' => 'official_hyperpay_charge_contract_missing',
            'credentials_present' => $this->hasConfiguredCredentials(),
        ]);

        return new HyperPayRemoteCheckoutResult(
            status: 'blocked_missing_official_contract',
            reason: 'official_hyperpay_charge_contract_missing',
            merchantReference: $merchantReference,
            meta: [
                'credentials_present' => $this->hasConfiguredCredentials(),
                'enabled' => (bool) config('services.hyperpay.enabled', false),
            ],
        );
    }

    public function verifySubscriptionWebhook(array $headers, array $payload, ?string $rawBody = null): SubscriptionPaymentVerification
    {
        $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? Str::uuid());
        $providerCheckoutId = isset($payload['provider_checkout_id']) ? (string) $payload['provider_checkout_id'] : null;
        $claimedStatus = isset($payload['status']) ? strtolower((string) $payload['status']) : null;

        Log::warning('subscription.webhook.hyperpay_unconfigured', [
            'event_id' => $eventId,
            'reason' => 'official_hyperpay_webhook_contract_missing',
        ]);

        return SubscriptionPaymentVerification::rejected(
            eventId: $eventId,
            reason: 'hyperpay_verification_not_configured',
            providerCheckoutId: $providerCheckoutId,
            outcome: $claimedStatus,
        );
    }

    public function localFallbackForbidden(): bool
    {
        return ! SubscriptionBillingGuard::localProviderAllowed();
    }

    private function hasConfiguredCredentials(): bool
    {
        $entityId = trim((string) config('services.hyperpay.entity_id', ''));
        $accessToken = trim((string) config('services.hyperpay.access_token', ''));

        return $entityId !== '' && $accessToken !== '';
    }
}
