<?php

namespace Tests\Feature\Feature\Platform;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSubscriptionEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_edit_subscription_without_workspace_context(): void
    {
        $this->seed(FoundationSeeder::class);

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
        $plan = Plan::query()->where('is_active', true)->firstOrFail();

        $subscription = Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.subscriptions.edit', $subscription))
            ->assertOk()
            ->assertSee('الخطة', false)
            ->assertSee('trialing', false);
    }
}
