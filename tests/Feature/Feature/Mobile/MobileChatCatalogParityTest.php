<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileChatCatalogParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_category_crud_inventory_and_variants_are_workspace_scoped(): void
    {
        [, $workspaceA] = $this->seedWorkspace('Catalog Parity A');
        [, $workspaceB] = $this->seedWorkspace('Catalog Parity B');
        $this->enableWorkspaceFeature($workspaceA, 'products');
        $this->enableWorkspaceFeature($workspaceB, 'products');
        $managerA = $this->memberWithPermissions($workspaceA, ['chat.catalog.view', 'chat.catalog.manage']);
        $managerB = $this->memberWithPermissions($workspaceB, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog/categories', [
                'name' => 'Category A',
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Category A');

        $categoryAId = (int) ChatCatalogCategory::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('name', 'Category A')
            ->value('id');

        $this->actingAsMobile($managerB, $workspaceB)
            ->patchJson('/api/mobile/v1/catalog/categories/'.$categoryAId, ['name' => 'Hijack'])
            ->assertNotFound();

        $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'Item With Variant',
                'price' => 10,
                'currency' => 'SAR',
                'chat_catalog_category_id' => $categoryAId,
                'inventory_policy' => 'finite_stock',
                'stock' => 5,
                'variants' => [
                    ['name' => 'Small', 'price' => 9, 'stock' => 2, 'inventory_policy' => 'finite_stock'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.variants.0.name', 'Small');

        $itemId = (int) ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('name', 'Item With Variant')
            ->value('id');

        $this->actingAsMobile($managerA, $workspaceA)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId.'/inventory', [
                'inventory_policy' => 'finite_stock',
                'stock' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', 8);

        $this->actingAsMobile($managerA, $workspaceA)
            ->getJson('/api/mobile/v1/catalog/categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Category A']);

        $this->actingAsMobile($managerB, $workspaceB)
            ->getJson('/api/mobile/v1/catalog/categories')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Category A']);

        $this->actingAsMobile($managerA, $workspaceA)
            ->deleteJson('/api/mobile/v1/catalog/categories/'.$categoryAId)
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(string $name): array
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

        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();

        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        return [$user, $workspace];
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

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();

        $token = $user->createToken('mobile-catalog-parity');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
