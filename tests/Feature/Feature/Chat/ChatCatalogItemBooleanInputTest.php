<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\ChatCatalogItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChatCatalogItemBooleanInputTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'owner_user_id' => $this->user->id,
            'type' => 'company',
            'name' => 'Bool Catalog WS',
        ]);
        $this->workspace->users()->attach($this->user->id, [
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
                ['workspace_id' => $this->workspace->id],
                [
                    'workspace_id' => $this->workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->workspace->id);
        $this->user->givePermissionTo(['chat.catalog.view', 'chat.catalog.manage']);
        $registrar->setPermissionsTeamId(null);
    }

    public function test_workspace_store_accepts_checkbox_style_booleans_for_digital_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.store'), [
                'name' => 'Netflix اشتراك شهر',
                'sku' => 'NETFLIX-1M',
                'price' => 5,
                'item_kind' => 'product',
                'tangibility' => 'intangible',
                'classification' => 'digital',
                'digital_subtype' => 'subscription',
                'inventory_policy' => 'digital_pool',
                'is_active' => '1',
                'is_visible' => '1',
                'is_searchable' => '1',
                'is_featured' => '0',
                'is_bestseller' => '0',
                'is_new' => '0',
                'is_recommended' => '0',
            ]);

        $response->assertRedirect(route('workspace.chat-catalog.index'));

        $this->assertDatabaseHas('chat_catalog_items', [
            'workspace_id' => $this->workspace->id,
            'sku' => 'NETFLIX-1M',
            'classification' => 'digital',
            'digital_subtype' => 'subscription',
            'is_active' => 1,
            'is_visible' => 1,
            'is_searchable' => 1,
            'is_featured' => 0,
            'is_bestseller' => 0,
            'is_new' => 0,
            'is_recommended' => 0,
        ]);
    }

    public function test_mobile_multipart_accepts_string_true_false_booleans(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $created = $this->actingAsMobile($this->user, $this->workspace)
            ->post('/api/mobile/v1/catalog', [
                'name' => 'Netflix اشتراك شهر',
                'sku' => 'NETFLIX-1M',
                'price' => '5.00',
                'currency' => 'SAR',
                'item_kind' => 'product',
                'tangibility' => 'intangible',
                'classification' => 'digital',
                'digital_subtype' => 'subscription',
                'inventory_policy' => 'digital_pool',
                'is_active' => 'true',
                'is_visible' => 'true',
                'is_searchable' => 'true',
                'is_featured' => 'false',
                'is_bestseller' => 'false',
                'is_new' => 'false',
                'is_recommended' => 'false',
            ])
            ->assertCreated();

        $itemId = (int) $created->json('data.id');
        $item = $this->inWorkspace($this->workspace, fn () => ChatCatalogItem::query()->findOrFail($itemId));

        $this->assertTrue($item->is_active);
        $this->assertTrue($item->is_visible);
        $this->assertTrue($item->is_searchable);
        $this->assertFalse($item->is_featured);
        $this->assertFalse($item->is_bestseller);
        $this->assertFalse($item->is_new);
        $this->assertFalse($item->is_recommended);
    }

    public function test_mobile_multipart_update_toggles_booleans(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $created = $this->actingAsMobile($this->user, $this->workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'كود رقمي',
                'price' => 10,
                'currency' => 'SAR',
                'classification' => 'digital',
                'digital_subtype' => 'code',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ])
            ->assertCreated();

        $itemId = (int) $created->json('data.id');

        $this->actingAsMobile($this->user, $this->workspace)
            ->patchJson('/api/mobile/v1/catalog/'.$itemId, [
                'is_active' => 'false',
                'is_visible' => 'false',
                'is_searchable' => 'false',
                'is_featured' => 'true',
            ])
            ->assertOk();

        $item = $this->inWorkspace($this->workspace, fn () => ChatCatalogItem::query()->findOrFail($itemId));
        $this->assertFalse($item->is_active);
        $this->assertFalse($item->is_visible);
        $this->assertFalse($item->is_searchable);
        $this->assertTrue($item->is_featured);
    }

    public function test_invalid_boolean_values_are_still_rejected(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $this->actingAsMobile($this->user, $this->workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'منتج',
                'price' => 10,
                'currency' => 'SAR',
                'is_active' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_physical_product_json_create_still_works(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $this->actingAsMobile($this->user, $this->workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'فستان',
                'price' => 100,
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => 'finite_stock',
                'stock' => 3,
                'is_active' => true,
            ])
            ->assertCreated();
    }

    public function test_mobile_accepts_digital_item_kind_alias_from_hasim_form(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $created = $this->actingAsMobile($this->user, $this->workspace)
            ->postJson('/api/mobile/v1/catalog', [
                'name' => 'كتاب رقمي',
                'price' => '12.50',
                'currency' => 'SAR',
                'item_kind' => 'digital',
                'tangibility' => 'intangible',
                'classification' => 'digital',
                'inventory_policy' => 'not_tracked',
                'is_active' => true,
            ])
            ->assertCreated();

        $item = $this->inWorkspace($this->workspace, fn () => ChatCatalogItem::query()->findOrFail((int) $created->json('data.id')));
        $this->assertSame('product', $item->item_kind);
        $this->assertSame('intangible', $item->tangibility);
        $this->assertSame('digital', $item->classification?->value);
    }

    private function actingAsMobile(User $user, Workspace $workspace): static
    {
        $this->flushHeaders();

        $token = $user->createToken('mobile-catalog-boolean');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Accept', 'application/json');
    }
}
