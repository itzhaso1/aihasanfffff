<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatCatalogWebFrontendTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->user = User::factory()->create();
        $this->user->workspaces()->attach($this->workspace->id, [
            'membership_role' => 'manager',
            'status' => 'active',
        ]);
    }

    public function test_chat_catalog_index_renders_for_authorized_user(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.index'));

        $response->assertOk();
        $response->assertViewIs('workspace.chat-catalog.index');
        $response->assertViewHas(['items', 'categories', 'catalog']);
    }

    public function test_chat_catalog_create_and_store_item(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.create'));

        $response->assertOk();
        $response->assertDontSee('الباركود');
        $response->assertDontSee('سعر التخفيض');
        $response->assertDontSee('السعر المقارن');
        $response->assertSee('طريقة التوفر');
        $response->assertSee('متاح دائمًا');
        $response->assertSee('كمية محددة');
        $response->assertSee('يُجهز عند الطلب');
        $response->assertSee('أكواد أو تراخيص');
        $response->assertSee('ملف للتحميل');
        $response->assertSee('حدد عدد الوحدات المتاحة للبيع.');
        $response->assertDontSee('طلب مسبق');
        $response->assertDontSee('value="backorder"', false);
        $response->assertDontSee('سياسة المخزون');

        $storeResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.store'), [
                'name' => 'فستان حرير راقي',
                'sku' => 'DRESS-001',
                'price' => 299.99,
                'currency' => 'SAR',
                'item_kind' => 'product',
                'classification' => 'physical',
                'inventory_policy' => 'finite_stock',
                'stock' => 10,
                'barcode' => '6291100000000',
                'sale_price' => 199.00,
                'compare_at_price' => 399.00,
                'variants' => [
                    [
                        'name' => 'مقاس كبير - أحمر',
                        'sku' => 'DRESS-001-L-RED',
                        'price' => 299.99,
                        'stock' => 5,
                    ],
                ],
            ]);

        $storeResponse->assertRedirect(route('workspace.chat-catalog.index'));
        $storeResponse->assertSessionHas('success');

        $this->assertDatabaseHas('chat_catalog_items', [
            'workspace_id' => $this->workspace->id,
            'name' => 'فستان حرير راقي',
            'sku' => 'DRESS-001',
            'barcode' => null,
            'sale_price' => null,
            'compare_at_price' => null,
        ]);
    }

    public function test_store_rejects_backorder_inventory_policy(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->from(route('workspace.chat-catalog.create'))
            ->post(route('workspace.chat-catalog.store'), [
                'name' => 'منتج محظور',
                'price' => 10,
                'currency' => 'SAR',
                'inventory_policy' => 'backorder',
            ])
            ->assertRedirect(route('workspace.chat-catalog.create'))
            ->assertSessionHasErrors('inventory_policy');

        $this->assertDatabaseMissing('chat_catalog_items', [
            'workspace_id' => $this->workspace->id,
            'name' => 'منتج محظور',
        ]);
    }

    public function test_web_variant_name_update_preserves_untouched_variant_fields(): void
    {
        $item = app(WorkspaceContext::class)->runFor($this->workspace, function (): ChatCatalogItem {
            return ChatCatalogItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'name' => 'فستان حرير راقي',
                'slug' => 'silk-dress',
                'sku' => 'DRESS-001',
                'price' => 299.99,
                'currency' => 'SAR',
                'item_kind' => 'product',
                'classification' => 'physical',
                'inventory_policy' => 'finite_stock',
                'stock' => 10,
            ]);
        });

        $variant = app(WorkspaceContext::class)->runFor($this->workspace, function () use ($item): ChatCatalogItemVariant {
            return $item->variants()->create([
                'workspace_id' => $this->workspace->id,
                'name' => 'مقاس كبير - أحمر',
                'sku' => 'DRESS-001-L-RED',
                'barcode' => '6291100000999',
                'price' => 299.99,
                'sale_price' => 249.50,
                'compare_at_price' => 349.00,
                'currency' => 'SAR',
                'stock' => 5,
                'inventory_policy' => 'backorder',
                'is_active' => false,
                'is_visible' => false,
                'status' => 'draft',
            ]);
        });

        $updateResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->put(route('workspace.chat-catalog.update', $item), [
                'name' => 'فستان حرير راقي',
                'price' => 299.99,
                'currency' => 'SAR',
                'variants' => [
                    [
                        'id' => $variant->id,
                        'name' => 'مقاس كبير - أحمر مطور',
                    ],
                ],
            ]);

        $updateResponse->assertRedirect(route('workspace.chat-catalog.index'));
        $updateResponse->assertSessionHas('success');

        $variant->refresh();
        $this->assertSame('مقاس كبير - أحمر مطور', $variant->name);
        $this->assertSame('DRESS-001-L-RED', $variant->sku);
        $this->assertSame('6291100000999', $variant->barcode);
        $this->assertSame('299.99', (string) $variant->price);
        $this->assertSame('249.50', (string) $variant->sale_price);
        $this->assertSame('349.00', (string) $variant->compare_at_price);
        $this->assertSame('SAR', $variant->currency);
        $this->assertSame(5, $variant->stock);
        $this->assertSame('backorder', $variant->inventory_policy?->value);
        $this->assertFalse((bool) $variant->is_active);
        $this->assertFalse((bool) $variant->is_visible);
        $this->assertSame('draft', $variant->status);
    }

    public function test_category_management_flow_create_update_delete(): void
    {
        // 1. Create Category
        $createResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.categories.store'), [
                'name' => 'ملابس نسائية',
                'slug' => 'womens-clothing',
            ]);

        $createResponse->assertRedirect();
        $createResponse->assertSessionHas('success');

        $category = ChatCatalogCategory::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'ملابس نسائية')
            ->firstOrFail();

        // 2. Update Category
        $updateResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->put(route('workspace.chat-catalog.categories.update', $category), [
                'name' => 'ملابس نسائية فاخرة',
            ]);

        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHas('success');

        $this->assertDatabaseHas('chat_catalog_categories', [
            'id' => $category->id,
            'name' => 'ملابس نسائية فاخرة',
        ]);

        // 3. Delete Category
        $deleteResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->delete(route('workspace.chat-catalog.categories.destroy', $category));

        $deleteResponse->assertRedirect();
        $deleteResponse->assertSessionHas('success');

        $this->assertSoftDeleted('chat_catalog_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_digital_inventory_index_and_bulk_secret_import(): void
    {
        $item = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ChatCatalogItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'بطاقة شحن 50 ريال',
            'slug' => 'card-50',
            'classification' => 'digital',
            'digital_subtype' => 'code',
            'inventory_policy' => 'digital_pool',
            'price' => 50,
            'currency' => 'SAR',
        ]));

        $indexResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.digital-inventory.index'));

        $indexResponse->assertOk();

        // Create Pool
        $poolResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.digital-inventory.pools.store'), [
                'chat_catalog_item_id' => $item->id,
                'name' => 'حوض أكواد 50 ريال',
                'low_stock_threshold' => 3,
            ]);

        $poolResponse->assertRedirect();
        $poolResponse->assertSessionHas('success');

        $pool = ChatCatalogDigitalInventoryPool::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace->id)
            ->where('name', 'حوض أكواد 50 ريال')
            ->firstOrFail();

        // Bulk Secret Import
        $importResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.digital-inventory.pools.import', $pool), [
                'unit_type' => 'code',
                'secrets_text' => "SECRET-CODE-001\nSECRET-CODE-002\nSECRET-CODE-003",
            ]);

        $importResponse->assertRedirect();
        $importResponse->assertSessionHas('success');

        $unitsCount = app(WorkspaceContext::class)->runFor($this->workspace, fn () => $pool->availableUnits()->count());
        $this->assertEquals(3, $unitsCount);

        $indexResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.digital-inventory.index'));
        $indexResponse->assertOk()->assertSee('حذف الحوض');

        $deleteResponse = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->delete(route('workspace.chat-catalog.digital-inventory.pools.destroy', $pool));
        $deleteResponse->assertRedirect();
        $deleteResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('chat_catalog_digital_inventory_pools', [
            'id' => $pool->id,
        ]);
    }
}
