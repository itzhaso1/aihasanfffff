<?php

namespace Tests\Feature\Feature\Security;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MassAssignmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_mobile_profile_update_cannot_mass_assign_password_or_login_metadata(): void
    {
        [$user, $workspace, $token] = $this->authMember('mass-profile@example.com');
        $originalHash = $user->password;

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->patchJson('/api/mobile/v1/auth/profile', [
                'name' => 'Updated Name',
                'password' => 'hacked-password',
                'last_login_at' => now()->toIso8601String(),
                'last_login_ip' => '9.9.9.9',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertSame($originalHash, $fresh->password);
        $this->assertNull($fresh->last_login_at);
        $this->assertNull($fresh->last_login_ip);
    }

    public function test_web_profile_update_cannot_mass_assign_password(): void
    {
        $user = User::factory()->create(['email' => 'mass-web@example.com']);
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Web Updated',
                'email' => $user->email,
                'password' => 'new-hacked-password',
            ])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame('Web Updated', $fresh->name);
        $this->assertSame($originalHash, $fresh->password);
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $email,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, (string) $login->json('data.token')];
    }
}
