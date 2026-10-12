<?php

namespace Tests\Feature\Mobile;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_workspace_scoped_conversations_and_customers(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('search@example.com');

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Unique Alpha Customer',
            'phone' => '+966511111111',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'external_id' => 'AlphaExternal',
            'last_message_at' => now(),
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/search?q='.urlencode('Alpha'));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.query', 'Alpha');
        $response->assertJsonPath('meta.workspace_id', $workspace->id);
        $this->assertNotEmpty($response->json('data.conversations'));
        $this->assertNotEmpty($response->json('data.customers'));
        $this->assertSame($conversation->id, $response->json('data.conversations.0.id'));
    }

    public function test_search_does_not_leak_other_workspace_rows(): void
    {
        $this->seed(FoundationSeeder::class);
        [$userA, $workspaceA, $tokenA] = $this->authMember('search-a@example.com');
        [$userB, $workspaceB] = $this->makeMember('search-b@example.com');

        Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Secret Beta Customer',
            'phone' => '+966522222222',
        ]);

        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'customer_id' => Customer::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->value('id'),
            'channel' => 'whatsapp',
            'status' => 'open',
            'external_id' => 'BetaExternal',
            'last_message_at' => now(),
        ]);

        $response = $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->getJson('/api/mobile/v1/search?q='.urlencode('Beta'));

        $response->assertOk();
        $this->assertSame([], $response->json('data.conversations'));
        $this->assertSame([], $response->json('data.customers'));
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
    {
        [$user, $workspace] = $this->makeMember($email);
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, $login->json('data.token')];
    }

    /**
     * @return array{0:User,1:Workspace}
     */
    private function makeMember(string $email): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'password',
        ]);
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
}
