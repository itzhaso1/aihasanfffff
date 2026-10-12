<?php

namespace Tests\Feature\Feature;

use App\Models\Plan;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_selection_creates_pending_checkout_session_and_does_not_activate_subscription_immediately(): void
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

        $plan = Plan::query()->create([
            'code' => 'company-test-plan',
            'name' => 'Company Test Plan',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 99,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['products', 'orders'],
            'permissions' => ['products.manage'],
            'limits' => ['products' => 100],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $plan->id,
                'payment_provider' => 'local',
            ]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->first();

        $response->assertRedirect(route('workspace.subscriptions.checkout.show', $session));

        $this->assertDatabaseHas('subscription_checkout_sessions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'checkout_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'subscription_status' => 'pending_activation',
            'payment_provider' => 'hyperpay',
        ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_frontend_checkout_confirmation_does_not_activate_subscription(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'store',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'code' => 'store-test-plan',
            'name' => 'Store Test Plan',
            'workspace_type' => 'store',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 49,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['inventory'],
            'permissions' => ['inventory.manage'],
            'limits' => ['inventory' => 500],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $plan->id,
                'payment_provider' => 'local',
            ]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.checkout.confirm-payment', $session), [
                'payment_reference' => 'pay_test_123',
                'payment_status' => 'paid',
                'verified' => true,
            ]);

        $response->assertRedirect(route('workspace.subscriptions.checkout.show', $session));

        $this->assertDatabaseHas('subscription_checkout_sessions', [
            'id' => $session->id,
            'payment_status' => 'pending',
            'subscription_status' => 'pending_activation',
            'checkout_status' => 'awaiting_payment',
        ]);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }
}
