<?php

namespace Tests\Feature\Mobile;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileParityFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $this->seed(FoundationSeeder::class);

        $owner = User::factory()->create([
            'email' => 'owner@hasim-test.com',
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
            'name' => 'Hasim Test Workspace',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $token = $owner->createToken('test', ['*'], now()->addDay())->plainTextToken;

        return [$owner, $workspace, $token];
    }

    public function test_workspace_resource_returns_role_and_membership_role(): void
    {
        [$owner, $workspace, $token] = $this->setupTenant();

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workspace.role', 'owner')
            ->assertJsonPath('data.workspace.membership_role', 'owner');
    }

    public function test_conversation_close_and_reopen(): void
    {
        [$owner, $workspace, $token] = $this->setupTenant();

        $customer = Customer::create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer A',
            'phone' => '966500000001',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]);

        // Close
        $closeRes = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/close");

        $closeRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'closed');

        $this->assertSame('closed', $conversation->fresh()->status);

        // Reopen
        $reopenRes = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/reopen");

        $reopenRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'open');

        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_customer_deletion(): void
    {
        [$owner, $workspace, $token] = $this->setupTenant();

        $customer = Customer::create([
            'workspace_id' => $workspace->id,
            'name' => 'To Delete',
            'phone' => '966500000002',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->deleteJson("/api/mobile/v1/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_analytics_summary(): void
    {
        [$owner, $workspace, $token] = $this->setupTenant();

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/analytics?range=today');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'revenue',
                    'orders_count',
                    'customers_new',
                    'conversations_count',
                    'ai_responses_count',
                    'conversion_rate',
                    'timeline',
                    'peak_hours',
                    'top_products',
                ],
            ]);
    }

    public function test_employee_management_crud(): void
    {
        [$owner, $workspace, $token] = $this->setupTenant();

        // 1. List
        $listRes = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/employees');

        $listRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['employees', 'invitations', 'assignable_roles']]);

        // 2. Invite
        $inviteRes = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/employees/invite', [
                'email' => 'new-employee@hasim.test',
                'role' => 'agent',
            ]);

        $inviteRes->assertCreated()
            ->assertJsonPath('success', true);

        // 3. Create second member and update role
        $agent = User::factory()->create(['email' => 'agent@hasim.test']);
        $membership = WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $agent->id,
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $updateRes = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->patchJson("/api/mobile/v1/employees/{$membership->id}", [
                'membership_role' => 'manager',
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.membership_role', 'manager');

        // 4. Remove member
        $deleteRes = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->deleteJson("/api/mobile/v1/employees/{$membership->id}");

        $deleteRes->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('workspace_users', ['id' => $membership->id]);
    }
}
