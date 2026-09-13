<?php

namespace Tests\Feature\Feature;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Subscription\LocalSubscriptionBillingProvider;
use App\Services\Subscription\SubscriptionService;
use App\Services\Subscription\UnconfiguredSubscriptionBillingProvider;
use App\Support\Subscription\SubscriptionBillingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionSecurityPhase1Test extends TestCase
{
    use RefreshDatabase;

    public function test_regular_member_cannot_activate_plan_from_api_subscriptions(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $member = $this->attachMember($workspace, 'member');
        $plan = $this->makeOfficialPlan();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/subscriptions', [
                'plan_id' => $plan->id,
                'payment_status' => 'paid',
                'verified' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_paid_subscription_cannot_activate_without_verified_payment(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan(['price' => 199]);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions', [
                'plan_id' => $plan->id,
                'payment_provider' => 'local',
                'payment_status' => 'paid',
            ])
            ->assertStatus(405);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $plan->id,
            ]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->expectException(UntrustedSubscriptionActivationException::class);
        app(SubscriptionService::class)->completeCheckoutAndActivate(
            $workspace,
            $session,
            'frontend-confirmation',
            'pay_client_claim'
        );
    }

    public function test_frontend_payment_confirmation_cannot_activate_subscription_alone(): void
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
                'payment_reference' => 'frontend_says_paid',
                'payment_status' => 'paid',
                'subscription_status' => 'active',
            ])
            ->assertRedirect(route('workspace.subscriptions.checkout.show', $session));

        $this->assertSame('pending', $session->fresh()->payment_status);
        $this->assertSame('pending_activation', $session->fresh()->subscription_status);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => 'active',
        ]);
    }

    public function test_client_local_payment_provider_cannot_bypass_billing(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $plan->id,
                'payment_provider' => 'local',
            ])
            ->assertRedirect();

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame('hyperpay', $session->payment_provider);
        $this->assertSame('awaiting_payment', $session->checkout_status);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => 'active',
        ]);
    }

    public function test_local_billing_provider_works_in_testing_environment(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertInstanceOf(
            LocalSubscriptionBillingProvider::class,
            app(\App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface::class)
        );

        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $subscription = app(LocalSubscriptionBillingProvider::class)->activatePlan($workspace, $plan);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('local', $subscription->provider);
        $this->assertSame($workspace->id, $subscription->workspace_id);
        $this->assertSame($plan->id, $subscription->plan_id);
    }

    public function test_production_configuration_does_not_allow_local_billing_provider(): void
    {
        $this->assertSame(
            UnconfiguredSubscriptionBillingProvider::class,
            SubscriptionBillingGuard::billingProviderClass('production')
        );
        $this->assertSame(
            UnconfiguredSubscriptionBillingProvider::class,
            SubscriptionBillingGuard::billingProviderClass('staging')
        );
        $this->assertSame(
            LocalSubscriptionBillingProvider::class,
            SubscriptionBillingGuard::billingProviderClass('testing')
        );
        $this->assertSame(
            LocalSubscriptionBillingProvider::class,
            SubscriptionBillingGuard::billingProviderClass('local')
        );
        $this->assertFalse(SubscriptionBillingGuard::localProviderAllowed('production'));
        $this->assertFalse(SubscriptionBillingGuard::isTrustedVerificationSource('local_billing_provider', 'production'));
        $this->assertFalse(SubscriptionBillingGuard::isTrustedVerificationSource('frontend-confirmation', 'testing'));
    }

    public function test_local_provider_refuses_activation_in_production_environment(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->app['env'] = 'production';

        $this->expectException(UntrustedSubscriptionActivationException::class);
        app(LocalSubscriptionBillingProvider::class)->activatePlan($workspace, $plan);
    }

    public function test_unconfigured_provider_never_activates_subscription(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->expectException(UntrustedSubscriptionActivationException::class);
        app(UnconfiguredSubscriptionBillingProvider::class)->activatePlan($workspace, $plan);
    }

    public function test_unauthorized_workspace_member_cannot_cancel_subscription(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $agent = $this->attachMember($workspace, 'agent');
        $plan = $this->makeOfficialPlan();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.subscriptions.destroy', $subscription))
            ->assertForbidden();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => Subscription::STATUS_ACTIVE,
            'cancelled_at' => null,
        ]);
    }

    public function test_unauthorized_workspace_member_cannot_execute_billing_mutation(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $member = $this->attachMember($workspace, 'member');
        $plan = $this->makeOfficialPlan();

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $plan->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('subscription_checkout_sessions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_workspace_a_user_cannot_mutate_workspace_b_subscription(): void
    {
        [$userA, $workspaceA] = $this->makeWorkspaceOwner();
        [$userB, $workspaceB] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $subscriptionB = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $checkoutB = SubscriptionCheckoutSession::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'plan_id' => $plan->id,
            'checkout_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'subscription_status' => 'pending_activation',
            'payment_provider' => 'hyperpay',
            'provider_checkout_id' => 'subchk_idor_b',
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'expires_at' => now()->addHours(12),
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->delete(route('workspace.subscriptions.destroy', $subscriptionB))
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.subscriptions.checkout.confirm-payment', $checkoutB), [
                'payment_reference' => 'stolen',
            ])
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.subscriptions.checkout.show', $checkoutB))
            ->assertNotFound();

        $this->actingAsApi($userA, $workspaceA)
            ->postJson('/api/subscriptions', [
                'plan_id' => $plan->id,
                'workspace_id' => $workspaceB->id,
            ])
            ->assertStatus(405);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionB->id,
            'workspace_id' => $workspaceB->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspaceA->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspaceB->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_inactive_or_internal_plan_cannot_be_activated_by_user(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();

        $internal = Plan::query()->create([
            'code' => 'internal-vip',
            'name' => 'Internal VIP',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 1,
            'is_active' => true,
            'is_public' => false,
            'is_official' => false,
            'features' => [],
            'permissions' => [],
            'limits' => [],
        ]);

        $inactive = Plan::query()->create([
            'code' => 'retired-official',
            'name' => 'Retired Official',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 10,
            'is_active' => false,
            'is_public' => true,
            'is_official' => true,
            'features' => [],
            'permissions' => [],
            'limits' => [],
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $internal->id])
            ->assertNotFound();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $inactive->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('subscription_checkout_sessions', [
            'workspace_id' => $workspace->id,
        ]);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_trusted_local_verification_can_activate_checkout_in_testing(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $plan->id]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $subscription = app(SubscriptionService::class)->completeCheckoutAndActivate(
            $workspace,
            $session,
            'local_billing_provider',
            'pay_verified_local'
        );

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('local_billing_provider', $subscription->fresh()->metadata['activated_via'] ?? null);
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

    private function attachMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOfficialPlan(array $overrides = []): Plan
    {
        return Plan::query()->create(array_merge([
            'code' => 'security-pro-'.uniqid(),
            'name' => 'Security Pro',
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
        ], $overrides));
    }

    private function actingAsApi(User $user, Workspace $workspace)
    {
        $token = $user->createToken('api');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
