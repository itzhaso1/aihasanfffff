<?php

namespace Tests\Feature\Feature\Security;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        Role::findOrCreate('member', 'web');
    }

    public function test_staff_member_cannot_assign_roles_or_sync_permissions(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $staff = $this->attachMember($workspace, 'member');

        $this->actingAsApi($staff, $workspace)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staff->id,
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->actingAsApi($staff, $workspace)
            ->postJson('/api/roles-permissions/sync-permissions', [
                'user_id' => $owner->id,
                'permissions' => ['workspace.manage'],
            ])
            ->assertForbidden();
    }

    public function test_manager_cannot_assign_admin_or_owner(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $manager = $this->attachMember($workspace, 'manager');
        $staff = $this->attachMember($workspace, 'member');

        $this->actingAsApi($manager, $workspace)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staff->id,
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->actingAsApi($manager, $workspace)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staff->id,
                'role' => 'owner',
            ])
            ->assertForbidden();

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staff->id,
                'role' => 'manager',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager');
    }

    public function test_admin_cannot_escalate_self_or_assign_admin(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $admin = $this->attachMember($workspace, 'admin');
        $staff = $this->attachMember($workspace, 'member');

        $this->actingAsApi($admin, $workspace)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $admin->id,
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->actingAsApi($admin, $workspace)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staff->id,
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->actingAsApi($admin, $workspace)
            ->postJson('/api/roles-permissions/sync-permissions', [
                'user_id' => $admin->id,
                'permissions' => ['workspace.manage'],
            ])
            ->assertForbidden();
    }

    public function test_owner_can_assign_admin_and_cross_workspace_target_is_denied(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace();
        [, $workspaceB] = $this->makeWorkspace();
        $staffA = $this->attachMember($workspaceA, 'member');
        $staffB = $this->attachMember($workspaceB, 'member');

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staffA->id,
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/roles-permissions/assign-role', [
                'user_id' => $staffB->id,
                'role' => 'manager',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(): array
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

        return [$user, $workspace];
    }

    private function attachMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function actingAsApi(User $user, Workspace $workspace)
    {
        $this->flushHeaders();

        $token = $user->createToken('api');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
