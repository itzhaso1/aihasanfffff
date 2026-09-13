<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthAndWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_without_workspace_id_returns_token_and_workspaces(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember();

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email_or_phone' => $user->email,
            'password' => 'password',
            'device_name' => 'iPhone Test',
            'device_type' => 'ios',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user', 'workspace', 'workspaces']]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_me_and_workspace_switch_and_sessions(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspaceA] = $this->makeMember();
        $workspaceB = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $workspaceB->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspaceA->id,
            'device_name' => 'Android',
        ])->assertOk();

        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson('/api/mobile/v1/workspaces/switch', [
                'workspace_id' => $workspaceB->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $workspaceB->id);

        $sessions = $this->withToken($token)
            ->getJson('/api/mobile/v1/sessions')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($sessions->json('data')));
    }

    public function test_logout_revokes_current_token(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user] = $this->makeMember();

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('data.token');

        $this->withToken($token)
            ->postJson('/api/mobile/v1/auth/logout')
            ->assertOk();

        $this->assertSame(
            0,
            $user->tokens()->count(),
            'Expected personal access tokens to be revoked on logout.'
        );
    }

    /**
     * @return array{0:User,1:Workspace}
     */
    private function makeMember(): array
    {
        $user = User::factory()->create([
            'email' => 'mobile-user@example.com',
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
