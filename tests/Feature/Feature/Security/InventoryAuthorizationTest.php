<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_view_or_adjust_inventory(): void
    {
        [, $workspace] = $this->makeWorkspace();
        $staff = $this->attachMember($workspace, 'member');
        $product = $this->makeProduct($workspace);

        $this->actingAs($staff)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.inventory.index'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.inventory.store'), [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 1,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_view_and_adjust_inventory(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $product = $this->makeProduct($workspace);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.inventory.index'))
            ->assertOk();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.inventory.store'), [
                'product_id' => $product->id,
                'type' => 'add',
                'quantity' => 2,
            ])
            ->assertRedirect(route('workspace.inventory.index'));

        $this->assertSame(12, (int) $product->fresh()->stock);
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

    private function makeProduct(Workspace $workspace): Product
    {
        return Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Stock Item',
            'slug' => 'stock-item-'.$workspace->id,
            'sku' => 'STK-'.$workspace->id,
            'price' => 10,
            'currency' => 'SAR',
            'stock' => 10,
            'status' => 'active',
        ]);
    }
}
