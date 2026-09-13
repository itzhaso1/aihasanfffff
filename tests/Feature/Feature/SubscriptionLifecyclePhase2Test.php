<?php

namespace Tests\Feature\Feature;

use App\Jobs\ProcessSubscriptionWebhook;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\SubscriptionLifecycleService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Subscription\SubscriptionStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLifecyclePhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_expired_checkout_cannot_activate(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = $this->createCheckout($workspace, $plan, now()->subHour());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Checkout session has expired.');

        app(SubscriptionService::class)->completeCheckoutAndActivate(
            $workspace,
            $checkout,
            'local_billing_provider',
            'pay_late'
        );
    }

    public function test_webhook_cannot_activate_wrong_workspace(): void
    {
        config()->set('subscription.webhook_secret', 'sub_test_secret');

        [$userA, $workspaceA] = $this->makeWorkspaceOwner();
        [$userB, $workspaceB] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = $this->createCheckout($workspaceA, $plan);

        $this->postSignedSubscriptionWebhook('evt_ws_mismatch', [
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
            'workspace_id' => $workspaceB->id,
            'amount' => $plan->price,
            'currency' => $plan->currency,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'workspace_id' => $workspaceA->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspaceB->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        config()->set('subscription.webhook_secret', 'sub_test_secret');

        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = $this->createCheckout($workspace, $plan);
        $payload = [
            'event_id' => 'evt_dup_1',
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
            'payment_reference' => 'pay_dup',
        ];

        $this->postSignedSubscriptionWebhook('evt_dup_1', $payload);
        $this->postSignedSubscriptionWebhook('evt_dup_1', $payload);

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, \App\Models\WebhookEvent::withoutGlobalScopes()->where('external_event_id', 'evt_dup_1')->count());
    }

    public function test_invalid_webhook_cannot_activate(): void
    {
        config()->set('subscription.webhook_secret', 'sub_test_secret');

        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = $this->createCheckout($workspace, $plan);

        $payload = [
            'event_id' => 'evt_bad_sig',
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'paid',
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        ProcessSubscriptionWebhook::dispatchSync(
            provider: 'local',
            headers: [
                'x-webhook-timestamp' => (string) $timestamp,
                'x-webhook-signature' => 'not-a-valid-signature',
            ],
            payload: $payload,
            rawBody: $raw,
        );

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_failed_payment_cannot_activate(): void
    {
        config()->set('subscription.webhook_secret', 'sub_test_secret');

        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = $this->createCheckout($workspace, $plan);

        $this->postSignedSubscriptionWebhook('evt_fail_1', [
            'provider_checkout_id' => $checkout->provider_checkout_id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertSame('failed', $checkout->fresh()->payment_status);
    }

    public function test_renewal_without_verified_payment_cannot_activate_or_extend(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $periodEnd = now()->subDay();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $periodEnd,
        ]);

        $created = app(SubscriptionLifecycleService::class)->processRenewals();

        $this->assertSame(1, $created);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->fresh()->status);
        $this->assertSame(
            $periodEnd->toDateTimeString(),
            $subscription->fresh()->current_period_end->toDateTimeString()
        );
        $this->assertDatabaseHas('subscription_billing_attempts', [
            'subscription_id' => $subscription->id,
            'purpose' => SubscriptionBillingAttempt::PURPOSE_RENEWAL,
            'status' => SubscriptionBillingAttempt::STATUS_PENDING,
        ]);
    }

    public function test_trial_expiration_and_repeat_are_safe(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIALING,
            'starts_at' => now()->subDays(14),
            'trial_ends_at' => now()->subMinute(),
            'current_period_start' => now()->subDays(14),
            'current_period_end' => now()->subMinute(),
        ]);

        $lifecycle = app(SubscriptionLifecycleService::class);
        $this->assertSame(1, $lifecycle->expireTrials());
        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->fresh()->status);

        $this->assertSame(0, $lifecycle->expireTrials());
        $this->artisan('subscriptions:expire-trials')->assertSuccessful();
        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->fresh()->status);
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_past_due_keeps_entitlements_during_grace(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        app(SubscriptionLifecycleService::class)->markPastDue($subscription, 'test');

        $current = app(FeatureAccessService::class)->subscription($workspace);
        $this->assertNotNull($current);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $current->status);
        $this->assertTrue($current->grace_ends_at->isFuture());
    }

    public function test_grace_expiration_moves_past_due_to_expired(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDays(4),
            'grace_ends_at' => now()->subMinute(),
            'failed_payment_count' => 1,
        ]);

        $this->artisan('subscriptions:process-dunning')->assertSuccessful();

        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->fresh()->status);
        $this->assertNull(app(FeatureAccessService::class)->subscription($workspace));
    }

    public function test_paused_transition_is_supported_and_drops_entitlements(): void
    {
        $this->assertTrue(SubscriptionStateMachine::canTransition(
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAUSED
        ));

        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $paused = app(SubscriptionLifecycleService::class)->pause($subscription);
        $this->assertSame(Subscription::STATUS_PAUSED, $paused->status);
        $this->assertNull(app(FeatureAccessService::class)->subscription($workspace));

        $resumed = app(SubscriptionLifecycleService::class)->resume($paused);
        $this->assertSame(Subscription::STATUS_ACTIVE, $resumed->status);
        $this->assertNotNull(app(FeatureAccessService::class)->subscription($workspace));
    }

    public function test_verified_renewal_extends_period(): void
    {
        config()->set('subscription.webhook_secret', 'sub_test_secret');

        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $oldEnd = now()->subDay();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $oldEnd,
            'grace_ends_at' => now()->addDays(2),
            'failed_payment_count' => 1,
        ]);

        app(SubscriptionLifecycleService::class)->processRenewals();
        $attempt = SubscriptionBillingAttempt::withoutGlobalScopes()
            ->where('subscription_id', $subscription->id)
            ->where('purpose', SubscriptionBillingAttempt::PURPOSE_RENEWAL)
            ->firstOrFail();

        $this->postSignedSubscriptionWebhook('evt_renew_ok', [
            'provider_checkout_id' => $attempt->provider_checkout_id,
            'status' => 'paid',
            'payment_reference' => 'pay_renew',
            'amount' => $plan->price,
            'currency' => $plan->currency,
        ]);

        $fresh = $subscription->fresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->current_period_end->greaterThan($oldEnd));
        $this->assertSame(SubscriptionBillingAttempt::STATUS_PAID, $attempt->fresh()->status);
    }

    public function test_hyperpay_webhook_does_not_invent_verification(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $checkout = $this->createCheckout($workspace, $plan);

        ProcessSubscriptionWebhook::dispatchSync(
            provider: 'hyperpay',
            headers: ['x-webhook-signature' => 'anything'],
            payload: [
                'event_id' => 'hp_evt_1',
                'provider_checkout_id' => $checkout->provider_checkout_id,
                'status' => 'paid',
            ],
            rawBody: '{}',
        );

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_phase1_frontend_confirmation_still_cannot_activate(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $plan->id]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.checkout.confirm-payment', $session), [
                'payment_reference' => 'frontend',
            ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
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

    private function makeOfficialPlan(): Plan
    {
        return Plan::query()->create([
            'code' => 'lifecycle-pro-'.uniqid(),
            'name' => 'Lifecycle Pro',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 99,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard'],
            'permissions' => ['workspace.view'],
            'limits' => [],
        ]);
    }

    private function createCheckout(Workspace $workspace, Plan $plan, $expiresAt = null): SubscriptionCheckoutSession
    {
        $session = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);

        if ($expiresAt) {
            $session->forceFill(['expires_at' => $expiresAt])->save();
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedSubscriptionWebhook(string $eventId, array $payload): void
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
