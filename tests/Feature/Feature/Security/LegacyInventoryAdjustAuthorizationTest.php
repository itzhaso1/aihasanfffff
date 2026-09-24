<?php

namespace Tests\Feature\Feature\Security;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegacyInventoryAdjustAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_adjust_inventory_and_records_movement(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $product = $this->makeTrackedProduct($workspace, stock: 10);
        $movementCountBefore = InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count();

        $this->actingAsApi($owner, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 3,
                'notes' => 'Owner adjustment',
            ])
            ->assertCreated()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.type', 'add')
            ->assertJsonPath('data.before_quantity', 10)
            ->assertJsonPath('data.after_quantity', 13);

        $this->assertSame(13, (int) $product->fresh()->stock);
        $this->assertSame(
            $movementCountBefore + 1,
            InventoryMovement::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_manager_can_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $manager = $this->attachMember($workspace, 'manager');
        $product = $this->makeTrackedProduct($workspace, stock: 5);

        $this->actingAsApi($manager, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 2,
            ])
            ->assertCreated();

        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    public function test_member_with_inventory_manage_permission_can_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->memberWithPermissions($workspace, ['inventory.manage']);
        $product = $this->makeTrackedProduct($workspace, stock: 8);

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'remove',
                'quantity' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.after_quantity', 7);

        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    public function test_member_without_inventory_permission_cannot_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->attachMember($workspace, 'member');
        $product = $this->makeTrackedProduct($workspace, stock: 10);
        $movementCountBefore = InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 5,
            ])
            ->assertForbidden();

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(
            $movementCountBefore,
            InventoryMovement::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_member_with_products_manage_only_cannot_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->memberWithPermissions($workspace, ['products.manage']);
        $product = $this->makeTrackedProduct($workspace, stock: 4);

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(4, (int) $product->fresh()->stock);
    }

    public function test_non_member_cannot_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $outsider = User::factory()->create();
        $product = $this->makeTrackedProduct($workspace, stock: 6);

        $this->actingAsApi($outsider, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(6, (int) $product->fresh()->stock);
    }

    public function test_unauthenticated_request_cannot_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $product = $this->makeTrackedProduct($workspace, stock: 6);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 1,
            ])
            ->assertUnauthorized();

        $this->assertSame(6, (int) $product->fresh()->stock);
    }

    public function test_cross_workspace_product_id_is_rejected_without_mutation(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Inventory A');
        [, $workspaceB] = $this->makeWorkspace('Inventory B');
        $productB = $this->makeTrackedProduct($workspaceB, stock: 12, sku: 'B-SKU-1');
        $movementCountBefore = InventoryMovement::withoutGlobalScopes()->count();

        $this->actingAsApi($ownerA, $workspaceA)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $productB->id,
                'type' => 'add',
                'quantity' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);

        $this->assertSame(12, (int) $productB->fresh()->stock);
        $this->assertSame($movementCountBefore, InventoryMovement::withoutGlobalScopes()->count());
    }

    public function test_authorized_user_cannot_adjust_using_foreign_workspace_context(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Context A');
        [, $workspaceB] = $this->makeWorkspace('Context B');
        $productB = $this->makeTrackedProduct($workspaceB, stock: 9, sku: 'B-SKU-2');

        $this->actingAsApi($ownerA, $workspaceB)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $productB->id,
                'type' => 'add',
                'quantity' => 2,
            ])
            ->assertForbidden();

        $this->assertSame(9, (int) $productB->fresh()->stock);
    }

    public function test_view_inventory_movements_requires_view_permission_not_manage_only_member(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->attachMember($workspace, 'member');

        $this->actingAsApi($member, $workspace)
            ->getJson('/api/inventory/movements')
            ->assertForbidden();
    }

    public function test_member_with_products_manage_can_view_movements_but_not_adjust(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $member = $this->memberWithPermissions($workspace, ['products.manage']);
        $product = $this->makeTrackedProduct($workspace, stock: 3);

        $this->actingAsApi($member, $workspace)
            ->getJson('/api/inventory/movements')
            ->assertOk();

        $this->actingAsApi($member, $workspace)
            ->postJson('/api/inventory/adjust', [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(3, (int) $product->fresh()->stock);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name = 'Inventory Auth Workspace'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
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

    /**
     * @param  list<string>  $permissions
     */
    private function memberWithPermissions(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    private function makeTrackedProduct(Workspace $workspace, int $stock, string $sku = 'INV-AUTH-1'): Product
    {
        return Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Tracked '.$sku,
            'slug' => 'tracked-'.$sku,
            'sku' => $sku,
            'price' => 100,
            'currency' => 'SAR',
            'stock' => $stock,
            'inventory_tracking' => true,
            'status' => 'active',
        ]);
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
