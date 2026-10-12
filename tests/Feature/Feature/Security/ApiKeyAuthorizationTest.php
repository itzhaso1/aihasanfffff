<?php

namespace Tests\Feature\Feature\Security;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_create_or_list_api_keys(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $this->enableWorkspaceFeature($workspace, 'api');
        $staff = $this->attachMember($workspace, 'member');

        $this->actingAs($staff)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.api-keys.index'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.api-keys.store'), ['name' => 'stolen'])
            ->assertForbidden();

        $this->assertSame(0, ApiKey::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.api-keys.store'), ['name' => 'owner-key'])
            ->assertRedirect(route('workspace.api-keys.index'));

        $this->assertSame(1, ApiKey::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertArrayNotHasKey('key_hash', ApiKey::withoutGlobalScopes()->first()->toArray());
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
}
