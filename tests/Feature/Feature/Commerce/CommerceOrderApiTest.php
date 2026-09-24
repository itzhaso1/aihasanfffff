<?php

namespace Tests\Feature\Feature\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_authorized_api_uses_catalog_price_and_returns_pending_payment_order_without_sensitive_inventory(): void
    {
        [$user, $workspace, $item] = $this->commerceWorkspace();

        $cartResponse = $this->actingAsMobile($user, $workspace)
            ->withHeader('Idempotency-Key', 'api-cart-create-0001')
            ->postJson('/api/mobile/v1/commerce/carts', [
                'currency' => 'SAR',
                'channel' => 'chat',
                'workspace_id' => 999999,
                'commerce_seller_id' => 999999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'SAR');
        $cartId = (int) $cartResponse->json('data.id');

        $this->actingAsMobile($user, $workspace)
            ->withHeader('Idempotency-Key', 'api-cart-item-0001')
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/items', [
                'chat_catalog_item_id' => $item->id,
                'quantity' => 2,
                'unit_price' => '0.01',
                'commerce_seller_id' => 999999,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '30.00')
            ->assertJsonPath('data.items.0.price.total', '60.00');

        $conversion = $this->actingAsMobile($user, $workspace)
            ->withHeader('Idempotency-Key', 'api-order-convert-request-0001')
            ->postJson('/api/mobile/v1/commerce/carts/'.$cartId.'/convert', [
                'idempotency_key' => 'api-order-conversion-domain-0001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.required_next_step', 'payment_required')
            ->assertJsonMissingPath('data.items.0.encrypted_secret')
            ->assertJsonMissingPath('data.items.0.private_path');
        $orderId = (int) $conversion->json('data.id');

        $this->actingAsMobile($user, $workspace)
            ->getJson('/api/mobile/v1/commerce/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '30.00')
            ->assertJsonPath('data.seller_summaries.0.settlement_status', 'pending');

        $this->actingAsMobile($user, $workspace)
            ->getJson('/api/orders/'.$orderId)
            ->assertForbidden();
    }

    public function test_member_without_commerce_permissions_cannot_create_cart(): void
    {
        [, $workspace] = $this->commerceWorkspace();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAsMobile($member, $workspace)
            ->postJson('/api/mobile/v1/commerce/carts', ['currency' => 'SAR'])
            ->assertForbidden();
    }

    /**
     * @return array{0:User,1:Workspace,2:ChatCatalogItem}
     */
    private function commerceWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Commerce API workspace',
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

        $item = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $seller): ChatCatalogItem {
            $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
            $catalog->update([
                'commerce_seller_id' => $seller->id,
                'status' => ChatCatalogOperatingStatus::Published,
                'published_at' => now(),
                'default_currency' => 'SAR',
                'channel_visibility' => ['chat' => true],
            ]);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'commerce_seller_id' => $seller->id,
                'name' => 'API catalog item',
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
            ]);
        });

        return [$user, $workspace, $item];
    }

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();
        $token = $user->createToken('commerce-api-test');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
