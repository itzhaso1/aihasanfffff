<?php

namespace Tests\Unit\Feature;

use App\Exceptions\UsageLimitExceededException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_consume_usage_increments_meter(): void
    {
        [$workspace] = $this->workspaceWithPlan([
            'whatsapp_messages' => 10,
        ], ['whatsapp']);

        $service = app(FeatureAccessService::class);

        $row = $service->consumeUsage($workspace, 'whatsapp_messages', 3);
        $this->assertSame(3.0, (float) $row->used);
        $this->assertSame(3.0, $service->currentUsage($workspace, 'whatsapp_messages'));

        $row = $service->consumeUsage($workspace, 'whatsapp_messages', 2);
        $this->assertSame(5.0, (float) $row->used);
    }

    public function test_hard_block_throws_when_limit_exceeded(): void
    {
        [$workspace] = $this->workspaceWithPlan([
            'whatsapp_messages' => 2,
        ], ['whatsapp'], [
            'whatsapp_messages' => 'hard_block',
        ]);

        $service = app(FeatureAccessService::class);
        $service->consumeUsage($workspace, 'whatsapp_messages', 2);

        $this->expectException(UsageLimitExceededException::class);
        $service->consumeUsage($workspace, 'whatsapp_messages', 1);
    }

    /**
     * @param  array<string, int|float|null>  $limits
     * @param  array<int, string>  $features
     * @param  array<string, string>  $overageRules
     * @return array{0:Workspace,1:User}
     */
    private function workspaceWithPlan(array $limits, array $features = [], array $overageRules = []): array
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
            'code' => 'company_entitlement_test',
            'name' => 'Entitlement Test',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => $features,
            'limits' => $limits,
            'overage_rules' => $overageRules,
        ]);

        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        return [$workspace, $user];
    }
}
