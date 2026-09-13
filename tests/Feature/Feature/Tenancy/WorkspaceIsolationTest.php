<?php

namespace Tests\Feature\Feature\Tenancy;

use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_access_own_workspace_context(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'individual']);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/workspace/'.$workspace->id.'/current')
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id);
    }

    public function test_user_cannot_access_other_workspace_even_if_workspace_id_is_known(): void
    {
        $userA = User::factory()->create();
        $workspaceA = Workspace::factory()->create(['owner_user_id' => $userA->id, 'type' => 'individual']);
        $workspaceA->users()->attach($userA->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $userB = User::factory()->create();
        $workspaceB = Workspace::factory()->create(['owner_user_id' => $userB->id, 'type' => 'company']);
        $workspaceB->users()->attach($userB->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tokenA = $userA->createToken('api')->plainTextToken;

        $this->withToken($tokenA)
            ->getJson('/api/workspace/'.$workspaceB->id.'/current')
            ->assertForbidden();
    }

    public function test_product_from_another_workspace_is_not_visible(): void
    {
        $userA = User::factory()->create();
        $workspaceA = Workspace::factory()->create(['owner_user_id' => $userA->id, 'type' => 'company']);
        $workspaceA->users()->attach($userA->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $userB = User::factory()->create();
        $workspaceB = Workspace::factory()->create(['owner_user_id' => $userB->id, 'type' => 'company']);
        $workspaceB->users()->attach($userB->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $foreign = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Secret Product',
            'slug' => 'secret-product-b',
            'sku' => 'SEC-B',
            'price' => 1,
            'currency' => 'SAR',
            'stock' => 1,
            'status' => 'active',
        ]);

        $tokenA = $userA->createToken('api');
        $tokenA->accessToken->forceFill(['workspace_id' => $workspaceA->id])->save();

        $this->withToken($tokenA->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->getJson('/api/products/'.$foreign->id)
            ->assertNotFound();
    }
}
