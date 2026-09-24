<?php

namespace Tests\Feature\Feature\Platform;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSubscriptionGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_grant_enterprise_subscription_to_any_workspace(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->workspaceOwner('store');
        $plan = Plan::query()->where('code', 'enterprise')->firstOrFail();
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.subscriptions.create', ['workspace_id' => $workspace->id]))
            ->assertOk()
            ->assertSee('منح اشتراك', false)
            ->assertSee($owner->email, false);

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.subscriptions.store'), [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'current_period_end' => now()->addYear()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('platform.subscriptions.index'));

        $subscription = Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['active', 'trialing'])
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('platform_admin', $subscription->provider);
        $this->assertSame('platform_admin', $subscription->metadata['activated_via'] ?? null);
        $this->assertTrue(app(FeatureAccessService::class)->workspaceHasFeature($workspace->fresh(), 'pos'));
        $this->assertTrue(app(FeatureAccessService::class)->workspaceHasFeature($workspace->fresh(), 'whatsapp'));
        $this->assertTrue(app(FeatureAccessService::class)->workspaceHasFeature($workspace->fresh(), 'finance'));
    }

    public function test_platform_grant_replaces_existing_running_subscription(): void
    {
        $this->seed(FoundationSeeder::class);
        [, $workspace] = $this->workspaceOwner('company');
        $starter = Plan::query()->where('code', 'starter')->firstOrFail();
        $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $starter->id,
            'status' => 'trialing',
            'running_guard' => 'running',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
        ]);

        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.subscriptions.store'), [
                'workspace_id' => $workspace->id,
                'plan_id' => $enterprise->id,
                'status' => 'active',
            ])
            ->assertRedirect(route('platform.subscriptions.index'));

        $this->assertSame(1, Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(1, Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'cancelled')
            ->count());
    }

    public function test_regular_user_cannot_grant_platform_subscription(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->workspaceOwner('store');
        $plan = Plan::query()->where('code', 'enterprise')->firstOrFail();

        $this->actingAs($owner)
            ->post(route('platform.subscriptions.store'), [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertSame(0, Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->count());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceOwner(string $type): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => $type,
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }
}
