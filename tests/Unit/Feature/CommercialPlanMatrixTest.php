<?php

namespace Tests\Unit\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAddon;
use App\Models\WorkspaceFeatureFlag;
use App\Models\PlanAddon;
use App\Services\Feature\FeatureAccessService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialPlanMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_pro_business_enterprise_matrix_from_seeded_plans(): void
    {
        $this->seed(FoundationSeeder::class);
        $service = app(FeatureAccessService::class);

        $starter = $this->workspaceWithPlanCode('company_starter');
        $pro = $this->workspaceWithPlanCode('company_pro');
        $business = $this->workspaceWithPlanCode('company_business');
        $enterprise = $this->workspaceWithPlanCode('company_enterprise');

        $this->assertMatrix($service, $starter, [
            'ai' => true,
            'whatsapp' => false,
            'pos' => false,
            'finance' => false,
            'email' => false,
            'api' => false,
            'analytics' => false,
            'advanced_customers' => false,
        ]);

        $this->assertMatrix($service, $pro, [
            'whatsapp' => true,
            'pos' => true,
            'finance' => true,
            'email' => true,
            'analytics' => true,
            'api' => false,
            'advanced_customers' => false,
        ]);

        $this->assertMatrix($service, $business, [
            'api' => true,
            'advanced_customers' => true,
            'crm' => true,
        ]);

        $this->assertMatrix($service, $enterprise, [
            'ai' => true,
            'whatsapp' => true,
            'pos' => true,
            'finance' => true,
            'email' => true,
            'api' => true,
            'analytics' => true,
            'advanced_customers' => true,
            'white_label' => true,
        ]);
    }

    public function test_legacy_company_basic_maps_to_starter_entitlements(): void
    {
        $this->seed(FoundationSeeder::class);
        $service = app(FeatureAccessService::class);
        $workspace = $this->workspaceWithPlanCode('company_basic');

        $this->assertFalse($service->workspaceHasFeature($workspace, 'pos'));
        $this->assertTrue($service->workspaceHasFeature($workspace, 'ai'));
    }

    public function test_workspace_override_can_enable_feature_beyond_plan(): void
    {
        $this->seed(FoundationSeeder::class);
        $service = app(FeatureAccessService::class);
        $workspace = $this->workspaceWithPlanCode('company_starter');

        $this->assertFalse($service->workspaceHasFeature($workspace, 'api'));

        WorkspaceFeatureFlag::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'feature_key' => 'api',
            'enabled' => true,
        ]);

        $this->assertTrue($service->workspaceHasFeature($workspace, 'api'));
    }

    public function test_addon_can_grant_feature_and_limit_boost(): void
    {
        $this->seed(FoundationSeeder::class);
        $service = app(FeatureAccessService::class);
        $workspace = $this->workspaceWithPlanCode('company_starter');

        $addon = PlanAddon::query()->create([
            'code' => 'extra_api',
            'name' => 'API Pack',
            'meter_key' => 'api_calls',
            'quantity' => 1000,
            'price' => 10,
            'currency' => 'SAR',
            'billing_period' => 'monthly',
            'grants' => [
                'features' => ['api'],
                'limits' => ['api_calls' => 500],
            ],
            'is_active' => true,
        ]);

        WorkspaceAddon::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_addon_id' => $addon->id,
            'status' => 'active',
            'quantity' => 1,
            'starts_at' => now(),
        ]);

        $this->assertTrue($service->workspaceHasFeature($workspace, 'api'));
        $this->assertGreaterThanOrEqual(1500.0, (float) $service->limitValue($workspace, 'api_calls'));
    }

    public function test_platform_plans_index_shows_matrix_from_database(): void
    {
        $this->seed(FoundationSeeder::class);
        $admin = \App\Models\PlatformAdmin::query()->firstOrFail();

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.plans.index'))
            ->assertOk()
            ->assertSee('مصفوفة المنتج القياسية')
            ->assertSee('الذكاء الاصطناعي')
            ->assertSee('Starter');
    }

    public function test_starter_cannot_open_pos_or_finance_routes(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->ownerWithPlan('company_starter');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.cashier.index'))
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertRedirect(route('workspace.subscriptions.index'));
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertMatrix(FeatureAccessService $service, Workspace $workspace, array $expected): void
    {
        foreach ($expected as $feature => $enabled) {
            $this->assertSame(
                $enabled,
                $service->workspaceHasFeature($workspace, $feature),
                "Feature {$feature} expected ".($enabled ? 'true' : 'false')
            );
        }
    }

    private function workspaceWithPlanCode(string $code): Workspace
    {
        [$user, $workspace] = $this->ownerWithPlan($code);

        return $workspace;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function ownerWithPlan(string $code): array
    {
        $plan = Plan::query()->where('code', $code)->firstOrFail();
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $plan->workspace_type,
            'status' => 'active',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return [$user, $workspace];
    }
}
