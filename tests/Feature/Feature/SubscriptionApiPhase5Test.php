<?php

namespace Tests\Feature\Feature;

use App\Jobs\ProcessSubscriptionWebhook;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Subscription\UnconfiguredSubscriptionBillingProvider;
use App\Support\Subscription\SubscriptionBillingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionApiPhase5Test extends TestCase
{
    use RefreshDatabase;

    public function test_plans_return_official_active_public_catalog_with_database_price_and_currency(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $visible = $this->makeOfficialPlan([
            'code' => 'starter',
            'name' => 'Starter',
            'price' => 49.00,
            'currency' => 'SAR',
            'trial_days' => 7,
            'features' => ['dashboard'],
            'limits' => ['users' => 3],
            'permissions' => ['secret.permission'],
            'overage_rules' => ['users' => 'hard_block'],
        ]);
        $this->makeOfficialPlan([
            'code' => 'pro',
            'name' => 'Pro',
            'price' => 149.00,
            'currency' => 'SAR',
        ]);

        Plan::query()->create($this->planAttributes([
            'code' => 'retired-official',
            'name' => 'Retired',
            'is_active' => false,
            'is_public' => true,
            'is_official' => true,
        ]));
        Plan::query()->create($this->planAttributes([
            'code' => 'internal-vip',
            'name' => 'Internal VIP',
            'is_active' => true,
            'is_public' => false,
            'is_official' => false,
            'price' => 1,
        ]));
        Plan::query()->create($this->planAttributes([
            'code' => 'legacy-hidden',
            'name' => 'Legacy',
            'is_active' => true,
            'is_public' => true,
            'is_official' => false,
        ]));

        $response = $this->actingAsApi($owner, $workspace)
            ->getJson('/api/subscriptions/plans')
            ->assertOk()
            ->assertJsonMissingPath('data.0.permissions')
            ->assertJsonMissingPath('data.0.overage_rules')
            ->assertJsonMissingPath('data.0.metadata');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains(
            Plan::query()->where('code', 'retired-official')->value('id'),
            $ids
        );
        $this->assertNotContains(
            Plan::query()->where('code', 'internal-vip')->value('id'),
            $ids
        );
        $this->assertNotContains(
            Plan::query()->where('code', 'legacy-hidden')->value('id'),
            $ids
        );

        $starter = collect($response->json('data'))->firstWhere('id', $visible->id);
        $this->assertSame('starter', $starter['code']);
        $this->assertEquals('49.00', number_format((float) $starter['price'], 2, '.', ''));
        $this->assertSame('SAR', $starter['currency']);
        $this->assertSame(7, $starter['trial_days']);
        $this->assertSame(['dashboard'], $starter['features']);
        $this->assertSame(['users' => 3], $starter['limits']);
        $this->assertArrayNotHasKey('permissions', $starter);
        $this->assertArrayNotHasKey('overage_rules', $starter);
    }

    public function test_current_subscription_is_workspace_scoped_and_null_when_absent(): void
    {
        [$userA, $workspaceA] = $this->makeWorkspaceOwner();
        [$userB, $workspaceB] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAsApi($userA, $workspaceA)
            ->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.subscription', null)
            ->assertJsonStructure(['data' => ['entitlements' => ['features', 'limits', 'meters', 'subscription_status']]]);

        $subscriptionB = $this->activateSubscription($workspaceB, $plan);

        $this->actingAsApi($userA, $workspaceA)
            ->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.subscription', null);

        $this->actingAsApi($userB, $workspaceB)
            ->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.subscription.id', $subscriptionB->id)
            ->assertJsonPath('data.subscription.status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('data.subscription.plan.id', $plan->id)
            ->assertJsonMissingPath('data.subscription.metadata')
            ->assertJsonMissingPath('data.subscription.provider_subscription_id');
    }

    public function test_forged_subscription_id_cannot_be_read_or_cancelled(): void
    {
        [$userA, $workspaceA] = $this->makeWorkspaceOwner();
        [$userB, $workspaceB] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $subscriptionB = $this->activateSubscription($workspaceB, $plan);

        $this->actingAsApi($userA, $workspaceA)
            ->getJson('/api/subscriptions/'.$subscriptionB->id)
            ->assertNotFound();

        $this->actingAsApi($userA, $workspaceA)
            ->postJson('/api/subscriptions/cancel', [
                'subscription_id' => $subscriptionB->id,
                'workspace_id' => $workspaceB->id,
                'status' => 'cancelled',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionB->id,
            'workspace_id' => $workspaceB->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_checkout_uses_server_price_and_does_not_activate(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan([
            'price' => 199.00,
            'currency' => 'SAR',
        ]);

        $response = $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/checkout', [
                'plan_id' => $plan->id,
                'amount' => 1,
                'currency' => 'USD',
                'payment_provider' => 'local',
                'payment_status' => 'paid',
                'subscription_status' => 'active',
                'verified' => true,
                'paid' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.checkout_status', 'awaiting_payment')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.subscription_status', 'pending_activation')
            ->assertJsonPath('data.payment_provider', 'hyperpay')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.plan.id', $plan->id)
            ->assertJsonPath('data.activated_subscription_id', null)
            ->assertJsonPath('data.is_verified_paid', false)
            ->assertJsonMissingPath('data.metadata');

        $this->assertEquals('199.00', number_format((float) $response->json('data.amount'), 2, '.', ''));

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('awaiting_payment', $checkout->checkout_status);
        $this->assertSame('pending', $checkout->payment_status);
        $this->assertSame('hyperpay', $checkout->payment_provider);
        $this->assertSame('SAR', $checkout->currency);
        $this->assertEquals('199.00', number_format((float) $checkout->amount, 2, '.', ''));
    }

    public function test_inactive_and_internal_plans_are_rejected_for_checkout(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();

        $inactive = Plan::query()->create($this->planAttributes([
            'code' => 'retired-api',
            'is_active' => false,
            'is_public' => true,
            'is_official' => true,
        ]));
        $internal = Plan::query()->create($this->planAttributes([
            'code' => 'internal-api',
            'is_active' => true,
            'is_public' => false,
            'is_official' => false,
        ]));

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/checkout', ['plan_id' => $inactive->id])
            ->assertNotFound();

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/checkout', ['plan_id' => $internal->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('subscription_checkout_sessions', [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_checkout_status_is_read_only_and_marks_expiration(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/checkout', ['plan_id' => $plan->id])
            ->assertCreated();

        $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAsApi($owner, $workspace)
            ->getJson('/api/subscriptions/checkout/'.$checkout->id)
            ->assertOk()
            ->assertJsonPath('data.id', $checkout->id)
            ->assertJsonPath('data.checkout_status', 'awaiting_payment')
            ->assertJsonPath('data.is_expired', false);

        $checkout->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->actingAsApi($owner, $workspace)
            ->getJson('/api/subscriptions/checkout/'.$checkout->id.'?payment_status=paid')
            ->assertOk()
            ->assertJsonPath('data.is_expired', true)
            ->assertJsonPath('data.is_verified_paid', false)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.subscription_status', 'pending_activation');

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_owner_and_admin_can_manage_billing_but_member_cannot(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $admin = $this->attachMember($workspace, 'admin');
        $member = $this->attachMember($workspace, 'member');
        $plan = $this->makeOfficialPlan();
        $upgrade = $this->makeOfficialPlan(['price' => 250]);

        $this->actingAsApi($member, $workspace)
            ->getJson('/api/subscriptions/plans')
            ->assertOk();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/subscriptions/checkout', ['plan_id' => $plan->id])
            ->assertForbidden();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/subscriptions/change-plan', ['plan_id' => $upgrade->id])
            ->assertForbidden();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/subscriptions/cancel')
            ->assertForbidden();

        $this->actingAsApi($admin, $workspace)
            ->postJson('/api/subscriptions/checkout', ['plan_id' => $plan->id])
            ->assertCreated();

        $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAsApi($member, $workspace)
            ->getJson('/api/subscriptions/checkout/'.$checkout->id)
            ->assertForbidden();

        $this->actingAsApi($owner, $workspace)
            ->getJson('/api/subscriptions/checkout/'.$checkout->id)
            ->assertOk();
    }

    public function test_unauthenticated_subscription_api_is_rejected(): void
    {
        $this->getJson('/api/subscriptions/plans')->assertUnauthorized();
        $this->getJson('/api/subscriptions/current')->assertUnauthorized();
        $this->postJson('/api/subscriptions/checkout', ['plan_id' => 1])->assertUnauthorized();
        $this->postJson('/api/subscriptions/change-plan', ['plan_id' => 1])->assertUnauthorized();
        $this->postJson('/api/subscriptions/cancel')->assertUnauthorized();
        $this->postJson('/api/subscriptions', ['plan_id' => 1])->assertUnauthorized();
    }

    public function test_authorized_cancel_is_idempotent_and_rejects_invalid_state(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();
        $subscription = $this->activateSubscription($workspace, $plan);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/cancel', [
                'workspace_id' => 999,
                'user_id' => 999,
                'status' => 'active',
                'plan_id' => $plan->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $subscription->id)
            ->assertJsonPath('data.status', Subscription::STATUS_CANCELLED);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Subscription::STATUS_CANCELLED);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_CANCELLED,
        ]);
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $expiredWorkspaceOwner = $this->makeWorkspaceOwner();
        $expired = $this->activateSubscription($expiredWorkspaceOwner[1], $plan, Subscription::STATUS_EXPIRED);

        $this->actingAsApi($expiredWorkspaceOwner[0], $expiredWorkspaceOwner[1])
            ->postJson('/api/subscriptions/cancel')
            ->assertStatus(422);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $expired->id,
            'status' => Subscription::STATUS_EXPIRED,
        ]);
    }

    public function test_cancel_and_change_plan_cannot_target_another_workspace(): void
    {
        [$userA, $workspaceA] = $this->makeWorkspaceOwner();
        [$userB, $workspaceB] = $this->makeWorkspaceOwner();
        $starter = $this->makeOfficialPlan(['price' => 50]);
        $pro = $this->makeOfficialPlan(['price' => 150]);
        $this->activateSubscription($workspaceA, $starter);
        $subscriptionB = $this->activateSubscription($workspaceB, $pro);

        $checkoutB = SubscriptionCheckoutSession::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'plan_id' => $pro->id,
            'checkout_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'subscription_status' => 'pending_activation',
            'payment_provider' => 'hyperpay',
            'provider_checkout_id' => 'subchk_idor_api_b',
            'amount' => $pro->price,
            'currency' => $pro->currency,
            'expires_at' => now()->addHours(12),
        ]);

        $this->actingAsApi($userA, $workspaceA)
            ->getJson('/api/subscriptions/checkout/'.$checkoutB->id)
            ->assertNotFound();

        $this->actingAsApi($userA, $workspaceA)
            ->postJson('/api/subscriptions/change-plan', [
                'plan_id' => $pro->id,
                'workspace_id' => $workspaceB->id,
                'upgrade' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.change_kind', 'upgrade')
            ->assertJsonPath('data.checkout_status', 'awaiting_payment');

        $this->actingAsApi($userA, $workspaceA)
            ->postJson('/api/subscriptions/cancel', [
                'workspace_id' => $workspaceB->id,
                'subscription_id' => $subscriptionB->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Subscription::STATUS_CANCELLED);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspaceA->id,
            'plan_id' => $pro->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionB->id,
            'plan_id' => $pro->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_change_plan_kind_is_server_determined_and_requires_payment(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $starter = $this->makeOfficialPlan(['code' => 'starter', 'price' => 40]);
        $pro = $this->makeOfficialPlan(['code' => 'pro', 'price' => 140]);
        $this->activateSubscription($workspace, $starter);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/change-plan', [
                'plan_id' => $pro->id,
                'action' => 'downgrade',
                'upgrade' => false,
                'payment_status' => 'paid',
                'verified' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.change_kind', 'upgrade')
            ->assertJsonPath('data.checkout_status', 'awaiting_payment')
            ->assertJsonPath('data.activated_subscription_id', null);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/change-plan', [
                'plan_id' => $starter->id,
                'action' => 'upgrade',
            ])
            ->assertStatus(422);

        $this->activateSubscription($workspace, $pro, Subscription::STATUS_ACTIVE, replace: true);

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/change-plan', [
                'plan_id' => $starter->id,
                'action' => 'upgrade',
                'payment_status' => 'paid',
            ])
            ->assertCreated()
            ->assertJsonPath('data.change_kind', 'downgrade')
            ->assertJsonPath('data.checkout_status', 'awaiting_payment');

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $starter->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_direct_api_activation_and_client_payment_claims_remain_impossible(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions', [
                'plan_id' => $plan->id,
                'payment_status' => 'paid',
                'verified' => true,
                'amount' => 1,
                'currency' => 'USD',
            ])
            ->assertStatus(405);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertSame(
            UnconfiguredSubscriptionBillingProvider::class,
            SubscriptionBillingGuard::billingProviderClass('production')
        );
        $this->assertFalse(SubscriptionBillingGuard::localProviderAllowed('production'));
    }

    public function test_hyperpay_webhook_cannot_activate_api_checkout(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan();

        $checkoutId = $this->actingAsApi($owner, $workspace)
            ->postJson('/api/subscriptions/checkout', ['plan_id' => $plan->id])
            ->assertCreated()
            ->json('data.id');

        $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()->findOrFail($checkoutId);

        ProcessSubscriptionWebhook::dispatchSync(
            provider: 'hyperpay',
            headers: ['x-webhook-signature' => 'forged'],
            payload: [
                'event_id' => 'hp_api_paid_claim',
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
        $this->assertSame('awaiting_payment', $checkout->fresh()->checkout_status);
    }

    public function test_index_uses_resources_and_hides_internal_fields(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan(['permissions' => ['hidden.perm']]);
        $this->activateSubscription($workspace, $plan);

        $this->actingAsApi($owner, $workspace)
            ->getJson('/api/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.current.status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('data.current.plan.id', $plan->id)
            ->assertJsonMissingPath('data.current.metadata')
            ->assertJsonMissingPath('data.plans.0.permissions');
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
        return Plan::query()->create($this->planAttributes($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function planAttributes(array $overrides = []): array
    {
        return array_merge([
            'code' => 'api-plan-'.uniqid(),
            'name' => 'API Plan',
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
        ], $overrides);
    }

    private function activateSubscription(
        Workspace $workspace,
        Plan $plan,
        string $status = Subscription::STATUS_ACTIVE,
        bool $replace = false
    ): Subscription {
        if ($replace) {
            Subscription::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);
        }

        return Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
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
