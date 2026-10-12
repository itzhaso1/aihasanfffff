<?php

namespace Tests\Feature\Feature\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Commerce\CommerceCartService;
use App\Services\Commerce\CommerceOrderService;
use App\Services\Payment\PaymentService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercePaymentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_commerce_checkout_does_not_need_a_finance_invoice(): void
    {
        [$workspace, $item] = $this->commerceWorkspace();

        $order = $this->inWorkspace($workspace, function () use ($workspace, $item) {
            $carts = app(CommerceCartService::class);
            $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 1);

            return app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'pay-boundary-0001');
        });

        $this->assertSame('commerce', $order->source);
        $this->assertNull($order->finance_invoice_id);
        $this->assertNull($order->pos_cashier_invoice_id);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Commerce payment execution is not enabled in this phase.');
        app(PaymentService::class)->createPaymentLink($order);
    }

    public function test_payment_service_source_does_not_import_finance_services(): void
    {
        $source = file_get_contents(app_path('Services/Payment/PaymentService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Services\\Finance', $source);
        $this->assertStringNotContainsString('InvoicePaymentService', $source);
        $this->assertStringNotContainsString('FinanceInvoice', $source);
        $this->assertStringNotContainsString('isFinanceInvoiceBillable', $source);
    }

    public function test_webhook_settles_order_payment_and_ignores_legacy_finance_invoice_row(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        PaymentGateway::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'local',
            'status' => 'connected',
            'config' => [],
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'CHAT-PAY-1',
            'source' => 'commerce',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50,
            'finance_invoice_id' => null,
        ]);
        $orderPayment = Payment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'checkout_reference' => 'CHAT-PAY-1',
            'provider' => 'local',
            'provider_payment_id' => 'pay_chat_1',
            'idempotency_key' => 'idem_chat_1',
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'SAR',
            'payment_link' => 'https://example.test/pay/chat-1',
            'provider_payload' => [],
        ]);
        $legacyInvoicePayment = Payment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_id' => null,
            'billable_type' => Payment::BILLABLE_FINANCE_INVOICE,
            'billable_id' => 42,
            'checkout_reference' => 'FIN-INV-42',
            'provider' => 'local',
            'provider_payment_id' => 'pay_fin_42',
            'idempotency_key' => 'idem_fin_42',
            'status' => 'pending',
            'amount' => 80,
            'currency' => 'SAR',
            'payment_link' => 'https://example.test/pay/fin-42',
            'provider_payload' => [],
        ]);

        $this->processLocalWebhook([
            'event_id' => 'evt_chat_pay_1',
            'status' => 'paid',
            'reference' => 'CHAT-PAY-1',
        ]);
        $this->processLocalWebhook([
            'event_id' => 'evt_fin_inv_42',
            'status' => 'paid',
            'reference' => 'FIN-INV-42',
        ]);

        $this->assertSame('paid', $orderPayment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->finance_invoice_id);
        $this->assertSame('pending', $legacyInvoicePayment->fresh()->status);
        $this->assertTrue($legacyInvoicePayment->fresh()->isFinanceInvoiceBillable());
    }

    /**
     * @return array{0: Workspace, 1: ChatCatalogItem}
     */
    private function commerceWorkspace(): array
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
        $this->enableWorkspaceFeature($workspace, 'products');
        $this->enableWorkspaceFeature($workspace, 'orders');
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);

        $item = $this->inWorkspace($workspace, function () use ($workspace, $seller): ChatCatalogItem {
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
                'name' => 'Pay catalog item',
                'price' => '25.00',
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

        return [$workspace, $item];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function processLocalWebhook(array $payload): void
    {
        config()->set('payment.providers.local.webhook_secret', 'local_tenancy_secret');
        config()->set('payment.providers.local.webhook_tolerance_seconds', 300);

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'local_tenancy_secret');

        app(PaymentService::class)->processWebhook('local', [
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => $signature,
        ], $payload, $rawBody);
    }
}
