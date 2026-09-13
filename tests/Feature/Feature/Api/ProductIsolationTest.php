<?php

namespace Tests\Feature\Feature\Api;

use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_products_of_other_workspace(): void
    {
        $this->seed(FoundationSeeder::class);

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

        $productB = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Workspace B Product',
            'slug' => 'workspace-b-product',
            'sku' => 'B-001',
            'price' => 100,
            'currency' => 'USD',
            'stock' => 3,
            'status' => 'active',
        ]);

        $tokenA = $userA->createToken('api')->plainTextToken;

        $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->getJson('/api/products/'.$productB->id)
            ->assertNotFound();
    }
}
