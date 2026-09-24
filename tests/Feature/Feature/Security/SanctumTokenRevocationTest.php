<?php

namespace Tests\Feature\Feature\Security;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SanctumTokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_password_change_revokes_other_tokens_but_keeps_current_token(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('change-other@example.com');

        $oldDeviceToken = $this->loginToken($user, $workspace, 'Old Phone');
        $currentDeviceToken = $this->loginToken($user, $workspace, 'Current Phone');

        $this->withToken($oldDeviceToken)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk();

        $this->forgetAuthGuardState();

        $this->withToken($currentDeviceToken)
            ->putJson('/api/mobile/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'changed-password-123',
                'password_confirmation' => 'changed-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTokenIsRevoked($oldDeviceToken);

        $this->withToken($currentDeviceToken)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_mobile_password_change_allows_fresh_login_with_new_token(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('change-login@example.com');
        $token = $this->loginToken($user, $workspace);

        $this->withToken($token)
            ->putJson('/api/mobile/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'changed-password-123',
                'password_confirmation' => 'changed-password-123',
            ])
            ->assertOk();

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'changed-password-123',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $newToken = $login->json('data.token');
        $this->assertNotSame($token, $newToken);

        $this->withToken($newToken)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk();
    }

    public function test_mobile_password_reset_revokes_existing_tokens(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('reset-mobile@example.com');
        $oldToken = $this->loginToken($user, $workspace);

        $this->withToken($oldToken)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk();

        $resetToken = Password::broker()->createToken($user);
        $newPassword = 'mobile-reset-password-123';

        $this->postJson('/api/mobile/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTokenIsRevoked($oldToken);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => $newPassword,
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $this->withToken($login->json('data.token'))
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk();
    }

    public function test_cashier_password_reset_revokes_existing_tokens(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('reset-cashier@example.com');

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $oldToken = $login->json('data.token');

        $resetToken = Password::broker()->createToken($user);
        $newPassword = 'cashier-reset-password-123';

        $this->postJson('/api/cashier/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        $this->assertTokenIsRevoked($oldToken);
    }

    public function test_password_change_does_not_revoke_other_users_tokens(): void
    {
        $this->seed(FoundationSeeder::class);
        [$userA, $workspaceA] = $this->makeMember('user-a@example.com');
        [$userB, $workspaceB] = $this->makeMember('user-b@example.com');

        $tokenA = $this->loginToken($userA, $workspaceA);
        $tokenB = $this->loginToken($userB, $workspaceB);

        $this->withToken($tokenA)
            ->putJson('/api/mobile/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'changed-password-123',
                'password_confirmation' => 'changed-password-123',
            ])
            ->assertOk();

        $this->forgetAuthGuardState();

        $this->withToken($tokenB)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $userB->email);
    }

    public function test_password_reset_does_not_revoke_other_users_tokens(): void
    {
        $this->seed(FoundationSeeder::class);
        [$userA, $workspaceA] = $this->makeMember('reset-a@example.com');
        [$userB, $workspaceB] = $this->makeMember('reset-b@example.com');

        $tokenB = $this->loginToken($userB, $workspaceB);
        $resetToken = Password::broker()->createToken($userA);

        $this->postJson('/api/mobile/v1/auth/reset-password', [
            'email' => $userA->email,
            'token' => $resetToken,
            'password' => 'reset-a-password-123',
            'password_confirmation' => 'reset-a-password-123',
        ])->assertOk();

        $this->forgetAuthGuardState();

        $this->withToken($tokenB)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk();
    }

    public function test_workspace_isolation_remains_after_password_reset(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspaceA] = $this->makeMember('isolation@example.com');
        $workspaceB = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $workspaceB->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $token = $this->loginToken($user, $workspaceA);
        $resetToken = Password::broker()->createToken($user);
        $newPassword = 'isolation-reset-password-123';

        $this->postJson('/api/mobile/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        $this->assertTokenIsRevoked($token);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => $newPassword,
            'workspace_id' => $workspaceA->id,
        ])->assertOk();

        $this->withToken($login->json('data.token'))
            ->withHeader('X-Workspace-Id', (string) $workspaceB->id)
            ->postJson('/api/mobile/v1/workspaces/switch', [
                'workspace_id' => $workspaceB->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $workspaceB->id);
    }

    public function test_web_password_change_revokes_sanctum_tokens_without_breaking_session(): void
    {
        $user = User::factory()->create();
        $apiToken = $user->createToken('api', ['*'])->plainTextToken;

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-web-password',
                'password_confirmation' => 'new-web-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-web-password', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertTokenIsRevoked($apiToken);
    }

    public function test_web_password_reset_revokes_existing_sanctum_tokens(): void
    {
        $user = User::factory()->create();
        $apiToken = $user->createToken('api', ['*'])->plainTextToken;
        $resetToken = Password::broker()->createToken($user);
        $newPassword = 'web-reset-password-123';

        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'));

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertTokenIsRevoked($apiToken);
    }

    private function assertTokenIsRevoked(string $token): void
    {
        $this->assertNull(PersonalAccessToken::findToken($token));

        $this->forgetAuthGuardState();

        $this->withToken($token)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertUnauthorized();
    }

    private function forgetAuthGuardState(): void
    {
        $this->app['auth']->forgetGuards();
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

    private function loginToken(User $user, Workspace $workspace, ?string $deviceName = null): string
    {
        $payload = [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ];

        if ($deviceName !== null) {
            $payload['device_name'] = $deviceName;
        }

        $response = $this->postJson('/api/mobile/v1/auth/login', $payload)->assertOk();

        return (string) $response->json('data.token');
    }
}
