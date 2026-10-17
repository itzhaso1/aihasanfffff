<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
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

    public function test_worker_prices_persist_and_omitted_variants_are_deleted(): void
    {
        [, $workspaceA] = $this->seedWorkspace('Catalog Prices A');
        [, $workspaceB] = $this->seedWorkspace('Catalog Prices B');
        $this->enableWorkspaceFeature($workspaceA, 'products');
        $this->enableWorkspaceFeature($workspaceB, 'products');
        $managerA = $this->memberWithPermissions($workspaceA, ['chat.catalog.view', 'chat.catalog.manage']);
        $managerB = $this->memberWithPermissions($workspaceB, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($managerA, $workspaceA)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'قميص',
                'price' => 40,
                'currency' => 'SAR',
                'worker_min_price' => 22,
                'worker_max_price' => 30,
                'variants' => [
                    ['name' => 'صغير', 'price' => 38],
                    ['name' => 'كبير', 'price' => 42],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.worker_min_price', '22.00')
            ->assertJsonPath('data.worker_max_price', null)
            ->assertJsonCount(2, 'data.variants');

        $itemId = (int) $created->json('data.id');
        $keepId = (int) $created->json('data.variants.1.id');

        $this->actingAsMobile($managerB, $workspaceB)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'worker_min_price' => 1,
                'variants' => [],
            ])
            ->assertNotFound();

        $this->assertSame('22.00', (string) ChatCatalogItem::withoutGlobalScopes()->find($itemId)?->worker_min_price);
        $this->assertSame(2, ChatCatalogItemVariant::withoutGlobalScopes()->where('chat_catalog_item_id', $itemId)->count());

        $this->actingAsMobile($managerA, $workspaceA)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'worker_min_price' => 18.5,
                'worker_max_price' => 28,
                'variants' => [
                    ['id' => $keepId, 'name' => 'كبير', 'price' => 42],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.worker_min_price', '18.50')
            ->assertJsonPath('data.worker_max_price', null)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.id', $keepId)
            ->assertJsonPath('data.variants.0.name', 'كبير');

        $this->actingAsMobile($managerA, $workspaceA)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'worker_min_price' => null,
                'worker_max_price' => null,
                'variants' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.worker_min_price', null)
            ->assertJsonPath('data.worker_max_price', null)
            ->assertJsonCount(0, 'data.variants');

        $item = ChatCatalogItem::withoutGlobalScopes()->find($itemId);
        $this->assertNotNull($item);
        $this->assertNull($item->worker_min_price);
        $this->assertNull($item->worker_max_price);
        $this->assertSame(0, ChatCatalogItemVariant::withoutGlobalScopes()->where('chat_catalog_item_id', $itemId)->whereNull('deleted_at')->count());
    }

    public function test_updating_variant_name_only_preserves_untouched_variant_fields(): void
    {
        [, $workspace] = $this->seedWorkspace('Catalog Variant Patch');
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($manager, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'فستان',
                'price' => 40,
                'currency' => 'SAR',
                'inventory_policy' => 'finite_stock',
                'variants' => [
                    [
                        'name' => 'صغير',
                        'sku' => 'DRESS-S',
                        'barcode' => '6291100000001',
                        'price' => 38,
                        'sale_price' => 30,
                        'compare_at_price' => 45,
                        'currency' => 'SAR',
                        'stock' => 4,
                        'inventory_policy' => 'on_request',
                        'is_active' => false,
                        'is_visible' => false,
                        'status' => 'draft',
                    ],
                ],
            ])
            ->assertCreated();

        $itemId = (int) $created->json('data.id');
        $variantId = (int) $created->json('data.variants.0.id');

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'name' => 'فستان',
                'price' => 40,
                'variants' => [
                    ['id' => $variantId, 'name' => 'صغير محدث'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.variants.0.id', $variantId)
            ->assertJsonPath('data.variants.0.name', 'صغير محدث')
            ->assertJsonPath('data.variants.0.sku', 'DRESS-S')
            ->assertJsonPath('data.variants.0.barcode', null)
            ->assertJsonPath('data.variants.0.price', '38.00')
            ->assertJsonPath('data.variants.0.sale_price', null)
            ->assertJsonPath('data.variants.0.compare_at_price', null)
            ->assertJsonPath('data.variants.0.currency', 'SAR')
            ->assertJsonPath('data.variants.0.stock', 4)
            ->assertJsonPath('data.variants.0.inventory_policy', 'on_request')
            ->assertJsonPath('data.variants.0.is_active', false)
            ->assertJsonPath('data.variants.0.is_visible', false)
            ->assertJsonPath('data.variants.0.status', 'draft');

        $variant = ChatCatalogItemVariant::withoutGlobalScopes()->find($variantId);
        $this->assertNotNull($variant);
        $this->assertSame('صغير محدث', $variant->name);
        $this->assertSame('DRESS-S', $variant->sku);
        $this->assertNull($variant->barcode);
        $this->assertSame('38.00', (string) $variant->price);
        $this->assertNull($variant->sale_price);
        $this->assertNull($variant->compare_at_price);
        $this->assertSame('SAR', $variant->currency);
        $this->assertSame(4, $variant->stock);
        $this->assertSame('on_request', $variant->inventory_policy?->value);
        $this->assertFalse((bool) $variant->is_active);
        $this->assertFalse((bool) $variant->is_visible);
        $this->assertSame('draft', $variant->status);
    }

    public function test_mobile_can_change_a_variant_price_even_when_stock_is_zero(): void
    {
        [, $workspace] = $this->seedWorkspace('Catalog Variant Price');
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($manager, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'تعلم Excel للمبتدئين',
                'price' => '10.00',
                'currency' => 'SAR',
                'inventory_policy' => 'not_tracked',
                'variants' => [[
                    'name' => 'عربي PDF',
                    'sku' => 'SKU-VAR-1',
                    'price' => 10,
                    'currency' => 'SAR',
                    'stock' => 0,
                    'inventory_policy' => 'finite_stock',
                    'is_active' => true,
                    'is_visible' => true,
                    'status' => 'published',
                    'sort_order' => 0,
                ]],
            ])
            ->assertCreated();

        $itemId = (int) $created->json('data.id');
        $variantId = (int) $created->json('data.variants.0.id');

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'name' => 'تعلم Excel للمبتدئين',
                'price' => '10.00',
                'currency' => 'SAR',
                'inventory_policy' => 'not_tracked',
                'variants' => [[
                    'id' => $variantId,
                    'name' => 'عربي PDF',
                    'sku' => 'SKU-VAR-1',
                    'price' => 15.5,
                    'currency' => 'SAR',
                    'stock' => 0,
                    'inventory_policy' => 'backorder',
                    'is_active' => true,
                    'is_visible' => true,
                    'status' => 'published',
                    'sort_order' => 0,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.variants.0.price', '15.50')
            ->assertJsonPath('data.variants.0.inventory_policy', 'finite_stock');

        $this->assertSame(
            '15.50',
            (string) ChatCatalogItemVariant::withoutGlobalScopes()->find($variantId)?->price,
        );
    }

    public function test_store_and_inventory_update_reject_backorder_but_legacy_rows_still_read(): void
    {
        [, $workspace] = $this->seedWorkspace('Catalog Backorder Guard');
        $this->enableWorkspaceFeature($workspace, 'products');
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $this->actingAsMobile($manager, $workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'محظور',
                'price' => 10,
                'currency' => 'SAR',
                'inventory_policy' => 'backorder',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['inventory_policy']);

        $item = $this->inWorkspace($workspace, fn () => ChatCatalogItem::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'قديم',
            'inventory_policy' => 'backorder',
            'stock' => 0,
        ]));

        $this->actingAsMobile($manager, $workspace)
            ->getJson('/api/mobile/v1/catalog/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.inventory_policy', 'backorder');

        $this->actingAsMobile($manager, $workspace)
            ->patchJson('/api/mobile/v1/catalog/'.$item->id.'/inventory', [
                'inventory_policy' => 'backorder',
                'stock' => 3,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['inventory_policy']);
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
