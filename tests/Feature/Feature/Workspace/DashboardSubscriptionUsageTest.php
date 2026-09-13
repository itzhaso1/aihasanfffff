<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSubscriptionUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_live_subscription_usage_badges(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $plan = Plan::query()->create([
            'code' => 'company-usage-test',
            'name' => 'Company Usage Plan',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 49,
            'is_active' => true,
            'features' => ['dashboard', 'conversations'],
            'permissions' => ['workspace.view'],
            'limits' => ['conversations' => 10],
        ]);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'current_period_start' => now()->subDays(2),
            'current_period_end' => now()->addDays(28),
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Usage Customer',
            'phone' => '0500004321',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'usage_customer',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
            'metadata' => [],
        ]);

        for ($i = 1; $i <= 3; $i++) {
            Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Message '.$i,
                'external_message_id' => 'usage_msg_'.$i,
                'created_at' => now()->subHours($i),
                'updated_at' => now()->subHours($i),
            ]);
        }

        Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Old message',
            'external_message_id' => 'usage_old_msg',
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.dashboard'))
            ->assertOk()
            ->assertSee('الباقة الحالية والاستخدام')
            ->assertSee('Company Usage Plan')
            ->assertSee('المتبقي')
            ->assertSee('سجل الاشتراكات')
            ->assertSee('مقارنة الباقات')
            ->assertSee('المزيد')
            ->assertDontSee('Messages Used', false)
            ->assertDontSee('إجمالي المبيعات')
            ->assertDontSee('Quick Actions')
            ->assertDontSee('مبيعات خلال آخر 7 أيام');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
