<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionCheckoutSession;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
use App\Support\Subscription\SubscriptionPaymentVerification;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionWebhookProcessor
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function process(string $providerName, array $headers, array $payload, ?string $rawBody = null): void
    {
        $verification = $this->verify($providerName, $headers, $payload, $rawBody);

        Log::info('subscription.payment.verification_received', $verification->redacted());

        if (! $verification->verified) {
            return;
        }

        $checkout = $this->findCheckout($verification);
        $attempt = $this->findAttempt($verification, $checkout);
        $workspaceId = $checkout?->workspace_id ?? $attempt?->workspace_id;

        if ($workspaceId === null) {
            Log::warning('subscription.webhook.unbound_event', [
                'event_id' => $verification->eventId,
                'verified' => $verification->verified,
                'reason' => 'checkout_or_attempt_not_found',
            ]);

            return;
        }

        app(WorkspaceContext::class)->runForId((int) $workspaceId, function () use ($providerName, $verification, $checkout, $attempt, $workspaceId): void {
        $webhookEvent = WebhookEvent::withoutGlobalScopes()->firstOrCreate(
            [
                'provider' => 'subscription:'.$providerName,
                'external_event_id' => $verification->eventId,
            ],
            [
                'workspace_id' => $workspaceId,
                'event_type' => $verification->outcome ?? 'unknown',
                'idempotency_key' => $verification->eventId,
                'headers' => [],
                'payload' => $verification->redacted(),
                'status' => $verification->verified ? 'pending' : 'invalid',
            ]
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return;
        }

        if (! $verification->verified) {
            $webhookEvent->update([
                'status' => 'invalid',
                'failed_reason' => $verification->reason,
                'processed_at' => now(),
            ]);

            return;
        }

        if (! $checkout && ! $attempt) {
            $webhookEvent->update([
                'status' => 'failed',
                'failed_reason' => 'checkout_or_attempt_not_found',
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($verification, $checkout, $attempt, $webhookEvent): void {
                if ($checkout) {
                    $this->applyCheckoutOutcome($checkout->fresh() ?? $checkout, $verification, $attempt);
                } elseif ($attempt) {
                    $this->applyAttemptOutcome($attempt->fresh() ?? $attempt, $verification);
                }

                $webhookEvent->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $webhookEvent->update([
                'status' => 'failed',
                'failed_reason' => 'processing_failed',
                'processed_at' => now(),
            ]);

            Log::error('subscription.webhook.processing_failed', [
                'event_id' => $verification->eventId,
                'reason' => $exception->getMessage(),
            ]);
        }
        });
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function verify(string $providerName, array $headers, array $payload, ?string $rawBody = null): SubscriptionPaymentVerification
    {
        return $this->resolveVerifier($providerName)->verifySubscriptionWebhook($headers, $payload, $rawBody);
    }

    private function resolveVerifier(string $providerName): SubscriptionBillingProviderInterface
    {
        $providerName = strtolower($providerName);

        if ($providerName === 'local') {
            return app(LocalSubscriptionBillingProvider::class);
        }

        if ($providerName === 'hyperpay') {
            return app(HyperPaySubscriptionBillingProvider::class);
        }

        return app(UnconfiguredSubscriptionBillingProvider::class);
    }

    private function findCheckout(SubscriptionPaymentVerification $verification): ?SubscriptionCheckoutSession
    {
        if (! $verification->providerCheckoutId) {
            return null;
        }

        return SubscriptionCheckoutSession::withoutGlobalScopes()
            ->where('provider_checkout_id', $verification->providerCheckoutId)
            ->first();
    }

    private function findAttempt(
        SubscriptionPaymentVerification $verification,
        ?SubscriptionCheckoutSession $checkout
    ): ?SubscriptionBillingAttempt {
        if ($checkout) {
            return SubscriptionBillingAttempt::withoutGlobalScopes()
                ->where('checkout_session_id', $checkout->id)
                ->latest('id')
                ->first();
        }

        if (! $verification->providerCheckoutId) {
            return null;
        }

        return SubscriptionBillingAttempt::withoutGlobalScopes()
            ->where('provider_checkout_id', $verification->providerCheckoutId)
            ->latest('id')
            ->first();
    }

    private function applyCheckoutOutcome(
        SubscriptionCheckoutSession $checkout,
        SubscriptionPaymentVerification $verification,
        ?SubscriptionBillingAttempt $attempt
    ): void {
        $this->assertAmountCurrency($checkout->amount, $checkout->currency, $verification);

        if ($attempt) {
            $attempt->forceFill([
                'provider_event_id' => $verification->eventId,
            ])->save();
        }

        if ($verification->isFailed()) {
            $subscription = $this->currentSubscription((int) $checkout->workspace_id);
            $this->lifecycle->applyVerifiedPaymentFailure($checkout, $subscription, 'verified_payment_failed');

            return;
        }

        if (! $verification->isPaid()) {
            return;
        }

        $workspace = Workspace::query()->findOrFail($checkout->workspace_id);

        if ($attempt && $attempt->purpose === SubscriptionBillingAttempt::PURPOSE_RENEWAL && $attempt->subscription_id) {
            $this->lifecycle->renewFromVerifiedAttempt(
                $attempt,
                'provider_webhook',
                $verification->paymentReference
            );

            return;
        }

        $this->lifecycle->activateVerifiedCheckout(
            $workspace,
            $checkout,
            'provider_webhook',
            $verification->paymentReference
        );
    }

    private function applyAttemptOutcome(
        SubscriptionBillingAttempt $attempt,
        SubscriptionPaymentVerification $verification
    ): void {
        $this->assertAmountCurrency($attempt->amount, $attempt->currency, $verification);

        $attempt->forceFill([
            'provider_event_id' => $verification->eventId,
        ])->save();

        if ($verification->isFailed()) {
            $subscription = $attempt->subscription_id
                ? Subscription::withoutGlobalScopes()->find($attempt->subscription_id)
                : $this->currentSubscription((int) $attempt->workspace_id);

            if ($attempt->checkout_session_id) {
                $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()->find($attempt->checkout_session_id);
                if ($checkout) {
                    $this->lifecycle->applyVerifiedPaymentFailure($checkout, $subscription, 'verified_payment_failed');

                    return;
                }
            }

            $attempt->update([
                'status' => SubscriptionBillingAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => 'verified_payment_failed',
            ]);

            if ($subscription) {
                $this->lifecycle->markPastDue($subscription, 'verified_payment_failed');
            }

            return;
        }

        if (! $verification->isPaid()) {
            return;
        }

        if ($attempt->purpose === SubscriptionBillingAttempt::PURPOSE_RENEWAL) {
            $this->lifecycle->renewFromVerifiedAttempt(
                $attempt,
                'provider_webhook',
                $verification->paymentReference
            );

            return;
        }

        if ($attempt->checkout_session_id) {
            $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()->findOrFail($attempt->checkout_session_id);
            $workspace = Workspace::query()->findOrFail($checkout->workspace_id);
            $this->lifecycle->activateVerifiedCheckout(
                $workspace,
                $checkout,
                'provider_webhook',
                $verification->paymentReference
            );
        }
    }

    private function assertAmountCurrency(
        mixed $expectedAmount,
        mixed $expectedCurrency,
        SubscriptionPaymentVerification $verification
    ): void {
        if ($verification->payloadCurrency !== null
            && strtoupper((string) $verification->payloadCurrency) !== strtoupper((string) $expectedCurrency)
        ) {
            Log::warning('subscription.payment.currency_mismatch', [
                'event_id' => $verification->eventId,
                'expected_currency' => strtoupper((string) $expectedCurrency),
            ]);

            throw new \RuntimeException('Verified payment currency does not match server-side plan currency.');
        }

        if ($verification->payloadAmount !== null
            && round((float) $verification->payloadAmount, 2) !== round((float) $expectedAmount, 2)
        ) {
            Log::warning('subscription.payment.amount_mismatch', [
                'event_id' => $verification->eventId,
            ]);

            throw new \RuntimeException('Verified payment amount does not match server-side plan price.');
        }
    }

    private function currentSubscription(int $workspaceId): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_PAST_DUE,
            ])
            ->latest('id')
            ->first();
    }
}
