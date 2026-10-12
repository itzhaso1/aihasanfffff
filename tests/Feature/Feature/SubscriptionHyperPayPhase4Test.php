<?php

namespace Tests\Feature\Feature;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Jobs\ProcessSubscriptionWebhook;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Subscription\HyperPaySubscriptionBillingProvider;
use App\Services\Subscription\SubscriptionService;
use App\Services\Subscription\UnconfiguredSubscriptionBillingProvider;
use App\Support\Subscription\SubscriptionBillingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SubscriptionHyperPayPhase4Test extends TestCase
{
    use RefreshDatabase;

    public function test_hyperpay_checkout_stays_awaiting_payment_and_does_not_invent_remote_session(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan(149, 'SAR');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $plan->id,
                'amount' => 1,
                'currency' => 'USD',
                'payment_provider' => 'local',
            ]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame('awaiting_payment', $session->checkout_status);
        $this->assertSame('pending', $session->payment_status);
        $this->assertSame('hyperpay', $session->payment_provider);
        $this->assertSame((string) $plan->price, (string) $session->amount);
        $this->assertSame('SAR', $session->currency);
        $this->assertSame('blocked_missing_official_contract', $session->metadata['hyperpay_remote_status'] ?? null);
        $this->assertNotEmpty($session->metadata['merchant_reference'] ?? null);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_hyperpay_webhook_with_claimed_paid_status_is_rejected(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        ProcessSubscriptionWebhook::dispatchSync(
            provider: 'hyperpay',
            headers: ['x-webhook-signature' => 'forged'],
            payload: [
                'event_id' => 'hp_paid_claim',
                'provider_checkout_id' => $checkout->provider_checkout_id,
                'status' => 'paid',
                'amount' => $plan->price,
                'currency' => $plan->currency,
            ],
            rawBody: '{}',
        );

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertSame('pending', $checkout->fresh()->payment_status);
    }

    public function test_unknown_hyperpay_status_is_rejected(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        $verification = app(HyperPaySubscriptionBillingProvider::class)->verifySubscriptionWebhook(
            ['authorization' => 'Bearer invented'],
            ['event_id' => 'hp_unknown', 'status' => 'SUCCESS', 'provider_checkout_id' => $checkout->provider_checkout_id],
            '{}'
        );

        $this->assertFalse($verification->verified);
        $this->assertSame('hyperpay_verification_not_configured', $verification->reason);
        $this->assertFalse($verification->isPaid());
    }

    public function test_amount_and_currency_mismatch_on_verified_local_webhook_does_not_activate(): void
    {
        Config::set('subscription.webhook_secret', 'phase4-secret');
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan(200, 'SAR');
        $checkout = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        $this->postSignedLocalWebhook('evt_amount_mismatch', [
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
            'amount' => 1,
            'currency' => 'USD',
            'payment_reference' => 'pay_bad',
        ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_wrong_checkout_id_does_not_activate(): void
    {
        Config::set('subscription.webhook_secret', 'phase4-secret');
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        $this->postSignedLocalWebhook('evt_wrong_checkout', [
            'provider_checkout_id' => 'subchk_does_not_exist',
            'status' => 'paid',
            'amount' => $plan->price,
            'currency' => $plan->currency,
        ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_duplicate_verified_webhook_does_not_create_second_subscription(): void
    {
        Config::set('subscription.webhook_secret', 'phase4-secret');
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan(80, 'SAR');
        $checkout = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        $payload = [
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'payment_reference' => 'pay_once',
        ];

        $this->postSignedLocalWebhook('evt_dup_once', $payload);
        $this->postSignedLocalWebhook('evt_dup_once', $payload);

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(Subscription::STATUS_ACTIVE, Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->value('status'));
    }

    public function test_browser_return_and_frontend_confirm_do_not_activate(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $plan->id]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.checkout.return', $session))
            ->assertRedirect(route('workspace.subscriptions.checkout.show', $session));

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.checkout.confirm-payment', $session), [
                'payment_status' => 'paid',
                'payment_provider' => 'hyperpay',
            ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_local_provider_is_rejected_outside_local_testing(): void
    {
        $this->assertFalse(SubscriptionBillingGuard::localProviderAllowed('production'));
        $this->assertFalse(SubscriptionBillingGuard::localProviderAllowed('staging'));
        $this->assertSame(
            UnconfiguredSubscriptionBillingProvider::class,
            SubscriptionBillingGuard::billingProviderClass('production')
        );
        $this->assertFalse(
            SubscriptionBillingGuard::isTrustedVerificationSource('local_billing_provider', 'production')
        );
    }

    public function test_hyperpay_provider_never_activates_or_falls_back_to_local(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $provider = app(HyperPaySubscriptionBillingProvider::class);

        $this->assertSame('hyperpay_pending', $provider->integrationMode());
        $this->assertTrue($provider->localFallbackForbidden() || app()->environment('testing'));

        $this->expectException(UntrustedSubscriptionActivationException::class);
        $provider->activatePlan($workspace, $plan);
    }

    public function test_expired_checkout_cannot_activate_after_claimed_hyperpay_payment(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $session = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);
        $session->forceFill([
            'expires_at' => now()->subHour(),
            'checkout_status' => 'expired',
        ])->save();

        $this->expectException(\RuntimeException::class);
        app(SubscriptionService::class)->completeCheckoutAndActivate(
            $workspace,
            $session->fresh(),
            'local_billing_provider',
            'hp_expired'
        );
    }

    public function test_renewal_attempt_does_not_extend_period_without_verified_payment(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $oldEnd = now()->addDay();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $oldEnd,
        ]);

        app(\App\Services\Subscription\SubscriptionLifecycleService::class)->processRenewals();

        $attempt = SubscriptionBillingAttempt::withoutGlobalScopes()
            ->where('subscription_id', $subscription->id)
            ->where('purpose', SubscriptionBillingAttempt::PURPOSE_RENEWAL)
            ->first();

        $this->assertNotNull($attempt);
        $this->assertSame(SubscriptionBillingAttempt::STATUS_PENDING, $attempt->status);
        $this->assertSame(
            $oldEnd->format('Y-m-d H:i:s'),
            $subscription->fresh()->current_period_end->format('Y-m-d H:i:s')
        );
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function makeOfficialPlan(float $price = 99, string $currency = 'SAR'): Plan
    {
        return Plan::query()->create([
            'code' => 'hp4-'.uniqid(),
            'name' => 'HyperPay Phase4',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => $currency,
            'price' => $price,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard'],
            'permissions' => [],
            'limits' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedLocalWebhook(string $eventId, array $payload): void
    {
        $payload['event_id'] = $eventId;
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, (string) config('subscription.webhook_secret'));

        $this->postJson('/api/webhooks/subscriptions/local', $payload, [
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => $signature,
        ])->assertStatus(202);
    }
}
