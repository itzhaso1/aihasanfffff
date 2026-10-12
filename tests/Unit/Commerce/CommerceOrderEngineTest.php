<?php

namespace Tests\Unit\Commerce;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Enums\Commerce\CommerceCartStatus;
use App\Enums\Commerce\CommerceOrderStatus;
use App\Enums\Commerce\CommerceReservationStatus;
use App\Exceptions\CommerceCartValidationException;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceInventoryReservation;
use App\Models\Commerce\Seller;
use App\Models\Order;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogDigitalInventoryService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Commerce\CommerceCartService;
use App\Services\Commerce\CommerceOrderService;
use App\Services\Commerce\CommerceWorkspaceProfileService;
use App\Services\Payment\PaymentService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_conversion_uses_authoritative_catalog_price_and_immutable_snapshot(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, [
            'price' => '100.00',
            'sale_price' => '80.00',
            'stock' => 5,
        ]);

        $carts = app(CommerceCartService::class);
        $orders = app(CommerceOrderService::class);
        $cart = $carts->create($workspace, currency: 'SAR');
        $cart = $carts->addItem($workspace, $cart, $item->id, null, 2);

        $line = $cart->items->first();
        $this->assertSame('100.00', $line->unit_price);
        $this->assertSame('200.00', $line->price_snapshot['subtotal']);
        $this->assertNull($line->price_snapshot['sale_price']);
        $this->assertNull($line->price_snapshot['compare_at_price']);

        $order = $orders->createDraftFromCart($workspace, $cart, 'conversion-key-0001');

        $this->assertSame('commerce', $order->source);
        $this->assertSame(CommerceOrderStatus::Draft, $order->commerce_status);
        $this->assertSame('draft', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('200.00', $order->total_amount);
        $this->assertSame('200.00', $order->items->first()->total_amount);
        $this->assertSame('100.00', $order->items->first()->price_snapshot['unit_price']);
        $this->assertSame($item->id, $order->items->first()->chat_catalog_item_id);
        $this->assertNull($order->items->first()->product_id);

        $item->update(['name' => 'Changed catalog name', 'price' => '20.00', 'sale_price' => null]);
        $recalculated = $orders->recalculateOrder($workspace, $order);

        $this->assertSame('200.00', $recalculated->total_amount);
        $this->assertSame('100.00', $recalculated->items->first()->unit_price);
        $this->assertNotSame('Changed catalog name', $recalculated->items->first()->product_name);
    }

    public function test_cart_conversion_is_idempotent_and_creates_one_pending_allocation_and_reservation(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, ['stock' => 3]);
        $carts = app(CommerceCartService::class);
        $orders = app(CommerceOrderService::class);

        $cart = $carts->addItem(
            $workspace,
            $carts->create($workspace, currency: 'SAR'),
            $item->id,
            null,
            2,
        );
        $first = $orders->createOrder($workspace, $cart, 'conversion-key-0002');
        $second = $orders->createOrder($workspace, $cart, 'conversion-key-0002');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(CommerceOrderStatus::PendingPayment, $first->commerce_status);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('source', 'commerce')->count());
        $this->assertSame(1, $first->sellerAllocations->count());
        $this->assertSame(1, $first->inventoryReservations->count());
        $this->assertSame('pending', $first->sellerAllocations->first()->settlement_status->value);
        $this->assertSame('0.00', $first->sellerAllocations->first()->platform_fee_amount);
        $this->assertSame(
            CommerceReservationStatus::Reserved,
            $first->inventoryReservations->first()->status,
        );

        $cart = CommerceCart::withoutGlobalScopes()->findOrFail($cart->id);
        $this->assertSame(CommerceCartStatus::Converted, $cart->status);
        $this->assertSame($first->id, $cart->converted_order_id);
    }

    public function test_multi_seller_order_preserves_line_ownership_and_has_no_forced_order_seller(): void
    {
        [$workspace, $sellerA] = $this->commerceWorkspace();
        $sellerB = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $itemA = $this->catalogItem($workspace->id, $sellerA->id, ['name' => 'Seller A item']);
        $itemB = $this->catalogItem($workspace->id, $sellerB->id, ['name' => 'Seller B item']);

        $carts = app(CommerceCartService::class);
        $cart = $carts->create($workspace, currency: 'SAR');
        $cart = $carts->addItem($workspace, $cart, $itemA->id, null, 1);
        $cart = $carts->addItem($workspace, $cart, $itemB->id, null, 1);
        $order = app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'conversion-key-0003');

        $this->assertNull($order->commerce_seller_id);
        $this->assertEqualsCanonicalizing(
            [$sellerA->id, $sellerB->id],
            $order->items->pluck('commerce_seller_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertCount(2, $order->sellerAllocations);
    }

    public function test_currency_mismatch_and_cross_workspace_seller_are_rejected(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, ['currency' => 'SAR']);
        $carts = app(CommerceCartService::class);
        $usdCart = $carts->create($workspace, currency: 'USD');

        try {
            $carts->addItem($workspace, $usdCart, $item->id, null, 1);
            $this->fail('Expected currency mismatch.');
        } catch (CommerceCartValidationException $exception) {
            $this->assertSame('pricing_invalid', $exception->problems[0]['code']);
        }

        $foreignWorkspace = Workspace::factory()->create();
        $foreignSeller = Seller::factory()->create(['workspace_id' => $foreignWorkspace->id]);
        $item->update(['commerce_seller_id' => $foreignSeller->id]);
        $cart = $carts->create($workspace, currency: 'SAR');

        try {
            $carts->addItem($workspace, $cart, $item->id, null, 1);
            $this->fail('Expected foreign seller rejection.');
        } catch (CommerceCartValidationException $exception) {
            $this->assertSame('seller_unavailable', $exception->problems[0]['code']);
        }
    }

    public function test_digital_units_are_reserved_without_exposing_the_secret_and_released_on_cancellation(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, [
            'classification' => 'digital',
            'digital_subtype' => 'license',
            'inventory_policy' => ChatCatalogInventoryPolicy::DigitalPool,
            'stock' => null,
        ]);
        $digital = app(ChatCatalogDigitalInventoryService::class);
        $pool = app(WorkspaceContext::class)->runFor($workspace, fn () => $digital->createPool(
            $workspace,
            $item,
            'Licenses',
        ));
        $unit = app(WorkspaceContext::class)->runFor($workspace, fn () => $digital->importSecretUnit(
            $pool,
            ChatCatalogDigitalUnitType::License,
            'do-not-expose-this-license',
        ));

        $carts = app(CommerceCartService::class);
        $cart = $carts->create($workspace, currency: 'SAR');

        try {
            $carts->addItem($workspace, $cart, $item->id, null, 1);
            $this->fail('Expected digital products to be rejected.');
        } catch (CommerceCartValidationException $exception) {
            $this->assertSame('digital_products_disabled', $exception->problems[0]['code']);
        }

        $this->assertSame(
            'available',
            ChatCatalogDigitalInventoryUnit::withoutGlobalScopes()->findOrFail($unit->id)->status->value,
        );
    }

    public function test_digital_order_requires_available_units(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, [
            'classification' => 'digital',
            'digital_subtype' => 'license',
            'inventory_policy' => ChatCatalogInventoryPolicy::DigitalPool,
            'stock' => null,
        ]);
        app(WorkspaceContext::class)->runFor($workspace, fn () => app(ChatCatalogDigitalInventoryService::class)->createPool(
            $workspace,
            $item,
            'Empty pool',
        ));
        $carts = app(CommerceCartService::class);
        $cart = $carts->create($workspace, currency: 'SAR');

        try {
            $carts->addItem($workspace, $cart, $item->id, null, 1);
            $this->fail('Expected unavailable digital inventory.');
        } catch (CommerceCartValidationException $exception) {
            $this->assertSame('digital_products_disabled', $exception->problems[0]['code']);
        }
    }

    public function test_finite_catalog_stock_counts_pending_commerce_reservations(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, ['stock' => 1]);
        $carts = app(CommerceCartService::class);
        $firstCart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 1);
        app(CommerceOrderService::class)->createDraftFromCart($workspace, $firstCart, 'conversion-key-0005');

        $secondCart = $carts->create($workspace, currency: 'SAR');
        try {
            $carts->addItem($workspace, $secondCart, $item->id, null, 1);
            $this->fail('Expected finite inventory reservation to prevent overselling.');
        } catch (CommerceCartValidationException $exception) {
            $this->assertSame('inventory_insufficient', $exception->problems[0]['code']);
        }
    }

    public function test_expired_cart_and_physical_reservation_no_longer_block_checkout(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, ['stock' => 1]);
        $carts = app(CommerceCartService::class);

        $expiredCart = $carts->create($workspace, expiresAt: now()->addMinute());
        $expiredCart->update(['expires_at' => now()->subMinute()]);
        $result = $carts->validate($workspace, $expiredCart);
        $this->assertFalse($result->isValid());
        $this->assertSame('cart_expired', $result->problems[0]['code']);
        $this->assertSame(
            CommerceCartStatus::Expired,
            CommerceCart::withoutGlobalScopes()->findOrFail($expiredCart->id)->status,
        );

        $firstCart = $carts->addItem(
            $workspace,
            $carts->create($workspace, expiresAt: now()->addMinute(), currency: 'SAR'),
            $item->id,
            null,
            1,
        );
        $order = app(CommerceOrderService::class)->createDraftFromCart($workspace, $firstCart, 'conversion-key-0009');
        $reservation = CommerceInventoryReservation::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->firstOrFail();
        $this->assertNotNull($reservation->expires_at);
        $reservation->update(['expires_at' => now()->subMinute()]);

        $secondCart = $carts->addItem(
            $workspace,
            $carts->create($workspace, currency: 'SAR'),
            $item->id,
            null,
            1,
        );
        $this->assertCount(1, $secondCart->items);
    }

    public function test_tax_exclusive_line_uses_fixed_money_totals_deterministically(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(CommerceWorkspaceProfileService::class)->updateDefaultTaxRate($workspace, '8.25');
        });
        $item = $this->catalogItem($workspace->id, $seller->id, [
            'price' => '10.00',
            'sale_price' => null,
            'tax_display_behavior' => 'tax_exclusive',
        ]);
        $carts = app(CommerceCartService::class);
        $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 3);
        $order = app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'conversion-key-0006');

        $this->assertSame('30.00', $order->subtotal);
        $this->assertSame('2.48', $order->tax_amount);
        $this->assertSame('32.48', $order->total_amount);
        $this->assertSame('8.25', $order->tax_rate);
    }

    public function test_service_requirement_is_snapshotted_without_booking_execution(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id, [
            'classification' => 'service',
            'inventory_policy' => ChatCatalogInventoryPolicy::ServiceCapacity,
            'stock' => null,
            'policies' => ['booking_required' => true, 'provider_required' => true],
        ]);
        $carts = app(CommerceCartService::class);
        $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 1);
        $order = app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'conversion-key-0007');

        $this->assertSame('service', $order->items->first()->fulfillment_classification);
        $this->assertTrue($order->items->first()->metadata['booking_required']);
        $this->assertSame('policy', $order->inventoryReservations->first()->reservation_kind);
    }

    public function test_payment_service_refuses_commerce_orders_in_this_phase(): void
    {
        [$workspace, $seller] = $this->commerceWorkspace();
        $item = $this->catalogItem($workspace->id, $seller->id);
        $carts = app(CommerceCartService::class);
        $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 1);
        $order = app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'conversion-key-0008');

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->createPaymentLink($order);
    }

    /**
     * @return array{0:Workspace,1:Seller}
     */
    private function commerceWorkspace(): array
    {
        $workspace = Workspace::factory()->create(['name' => 'Commerce workspace']);
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $seller): void {
            $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
            $catalog->update([
                'commerce_seller_id' => $seller->id,
                'status' => ChatCatalogOperatingStatus::Published,
                'published_at' => now(),
                'default_currency' => 'SAR',
                'channel_visibility' => [
                    'chat' => true,
                    'whatsapp' => false,
                    'instagram' => false,
                    'web' => false,
                    'marketplace' => false,
                ],
            ]);
        });

        return [$workspace, $seller];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function catalogItem(int $workspaceId, int $sellerId, array $overrides = []): ChatCatalogItem
    {
        return ChatCatalogItem::factory()->create([
            'workspace_id' => $workspaceId,
            'commerce_seller_id' => $sellerId,
            'name' => $overrides['name'] ?? 'Commerce item '.str()->random(8),
            'price' => '25.00',
            'sale_price' => null,
            'currency' => 'SAR',
            'classification' => 'physical',
            'inventory_policy' => ChatCatalogInventoryPolicy::FiniteStock,
            'stock' => 20,
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
            'published_at' => now(),
            'archived_at' => null,
            'tax_display_behavior' => 'tax_downstream',
            'policies' => [],
            ...$overrides,
        ]);
    }
}
