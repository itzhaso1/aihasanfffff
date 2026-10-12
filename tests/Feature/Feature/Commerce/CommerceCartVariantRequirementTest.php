<?php

namespace Tests\Feature\Feature\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Exceptions\CommerceCartValidationException;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Commerce\Seller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Commerce\CommerceCartService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceCartVariantRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_product_without_sellable_variants_can_be_added_to_cart(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.chat_catalog_item_id', $item->id)
            ->assertJsonPath('data.items.0.chat_catalog_item_variant_id', null);
    }

    public function test_inactive_only_variants_do_not_require_variant_selection(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();
        $this->createVariant($item, [
            'name' => 'مخفي',
            'is_active' => false,
            'is_visible' => true,
            'status' => 'published',
        ]);

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.chat_catalog_item_variant_id', null);
    }

    public function test_product_with_sellable_variant_requires_variant_id(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();
        $this->createVariant($item, ['name' => 'كبير']);

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب اختيار نسخة المنتج قبل إضافته إلى السلة.')
            ->assertJsonPath('errors.problems.0.code', 'variant_required');
    }

    public function test_product_with_sellable_variant_accepts_matching_variant_id(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();
        $variant = $this->createVariant($item, ['name' => 'كبير', 'price' => '32.00']);

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.chat_catalog_item_id', $item->id)
            ->assertJsonPath('data.items.0.chat_catalog_item_variant_id', $variant->id);
    }

    public function test_invalid_variant_id_is_rejected(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();
        $this->createVariant($item, ['name' => 'كبير']);

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => 999999,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.problems.0.code', 'variant_missing');
    }

    public function test_variant_from_another_product_is_rejected(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();
        $otherItem = $this->catalogItem($workspace, (int) $item->commerce_seller_id, ['name' => 'منتج آخر']);
        $foreignVariant = $this->createVariant($otherItem, ['name' => 'نسخة منتج آخر']);
        $this->createVariant($item, ['name' => 'نسخة هذا المنتج']);

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => $foreignVariant->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.problems.0.code', 'variant_missing');
    }

    public function test_variant_from_another_workspace_is_rejected(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();
        $this->createVariant($item, ['name' => 'محلي']);

        [, $foreignWorkspace, $foreignItem] = $this->commerceWorkspace('Foreign commerce workspace');
        $foreignVariant = $this->createVariant($foreignItem, ['name' => 'أجنبي']);

        $cartId = $this->createCart($user, $workspace);

        $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => $foreignVariant->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.problems.0.code', 'variant_missing');
    }

    public function test_service_add_item_without_variants_still_succeeds(): void
    {
        [, $workspace, $item] = $this->commerceWorkspace();
        $carts = app(CommerceCartService::class);

        $cart = app(WorkspaceContext::class)->runFor($workspace, function () use ($carts, $workspace, $item) {
            $cart = $carts->create($workspace, currency: 'SAR');

            return $carts->addItem($workspace, $cart, $item->id, null, 1);
        });

        $this->assertSame($item->id, (int) $cart->items->first()->chat_catalog_item_id);
        $this->assertNull($cart->items->first()->chat_catalog_item_variant_id);
    }

    public function test_service_add_item_with_sellable_variant_without_id_fails(): void
    {
        [, $workspace, $item] = $this->commerceWorkspace();
        $this->createVariant($item, ['name' => 'كبير']);
        $carts = app(CommerceCartService::class);

        try {
            app(WorkspaceContext::class)->runFor($workspace, function () use ($carts, $workspace, $item): void {
                $cart = $carts->create($workspace, currency: 'SAR');
                $carts->addItem($workspace, $cart, $item->id, null, 1);
            });
            $this->fail('Expected variant selection to be required.');
        } catch (CommerceCartValidationException $exception) {
            $this->assertSame('variant_required', $exception->problems[0]['code']);
            $this->assertSame('يجب اختيار نسخة المنتج قبل إضافته إلى السلة.', $exception->problems[0]['message']);
        }
    }

    /**
     * @return array{0:User,1:Workspace,2:ChatCatalogItem}
     */
    private function commerceWorkspace(string $name = 'Commerce variant workspace'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'name' => $name,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableWorkspaceFeature($workspace, 'products');
        $this->enableWorkspaceFeature($workspace, 'orders');
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);

        $item = $this->catalogItem($workspace, (int) $seller->id);

        return [$user, $workspace, $item];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function catalogItem(Workspace $workspace, int $sellerId, array $overrides = []): ChatCatalogItem
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $sellerId, $overrides): ChatCatalogItem {
            $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
            $catalog->update([
                'commerce_seller_id' => $sellerId,
                'status' => ChatCatalogOperatingStatus::Published,
                'published_at' => now(),
                'default_currency' => 'SAR',
                'channel_visibility' => ['chat' => true],
            ]);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'commerce_seller_id' => $sellerId,
                'name' => $overrides['name'] ?? 'Catalog item',
                'price' => '30.00',
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => ChatCatalogInventoryPolicy::FiniteStock,
                'stock' => 10,
                'is_active' => true,
                'is_visible' => true,
                'publication_status' => 'published',
                'published_at' => now(),
                'tax_display_behavior' => 'tax_downstream',
                ...$overrides,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVariant(ChatCatalogItem $item, array $overrides = []): ChatCatalogItemVariant
    {
        $workspace = Workspace::query()->findOrFail($item->workspace_id);

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($item, $overrides): ChatCatalogItemVariant {
            return $item->variants()->create([
                'workspace_id' => $item->workspace_id,
                'name' => $overrides['name'] ?? 'نسخة',
                'price' => $overrides['price'] ?? '30.00',
                'currency' => 'SAR',
                'stock' => 5,
                'inventory_policy' => ChatCatalogInventoryPolicy::FiniteStock,
                'is_active' => $overrides['is_active'] ?? true,
                'is_visible' => $overrides['is_visible'] ?? true,
                'status' => $overrides['status'] ?? 'published',
            ]);
        });
    }

    private function createCart(User $user, Workspace $workspace): int
    {
        $response = $this->actingAsMobile($user, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts', [
                'currency' => 'SAR',
                'channel' => 'chat',
            ])
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();
        $token = $user->createToken('commerce-variant-test');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
