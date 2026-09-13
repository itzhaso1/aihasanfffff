<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Finance\FinanceInvoice;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPhase2C2TakeawayCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_takeaway_cash_sale_marks_order_paid_without_a_gateway_payment(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 12);

        $orderPush = $this->push($token, $workspace, 'POS-2C2', $this->orderOperation(
            id: 'op-cash-order',
            reference: 'tw-cash-1',
            itemId: $item->id,
            unitPrice: 10,
            taxAmount: 0.90,
            totalAmount: 10.90,
        ));
        $this->assertTrue($orderPush['success']);

        $order = Order::query()->where('client_reference', 'tw-cash-1')->firstOrFail();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('cashier', $order->metadata['payment_method'] ?? null);
        $this->assertNull($order->pos_cashier_invoice_id);

        $invoiceOp = $this->invoiceOperation(
            id: 'op-cash-invoice',
            reference: 'tw-cash-1',
            orderId: (int) $order->id,
            totalAmount: 10.90,
        );
        $invoicePush = $this->push($token, $workspace, 'POS-2C2', $invoiceOp, register: false);
        $this->assertTrue($invoicePush['success']);
        $this->assertSame('applied', $invoicePush['accepted'][0]['status']);
        $this->assertSame('cash', $invoicePush['accepted'][0]['result']['payment_method']);
        $this->assertSame('paid', $invoicePush['accepted'][0]['result']['payment_status']);

        $order->refresh();
        $invoice = PosCashierInvoice::query()->firstOrFail();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('cash', $order->metadata['payment_method'] ?? null);
        $this->assertSame('cash', $invoice->metadata['payment_method'] ?? null);
        $this->assertSame($invoice->id, $order->pos_cashier_invoice_id);
        $this->assertSame((float) $order->total_amount, (float) $invoice->total_amount);
        $this->assertSame(10.90, (float) $order->total_amount);
        $this->assertSame(10.90, (float) $invoice->total_amount);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_catalog_price_change_does_not_reprice_order_invoice_or_cash_total(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);

        $this->push($token, $workspace, 'POS-2C2', $this->orderOperation(
            id: 'op-frozen-cash-order',
            reference: 'tw-frozen-cash',
            itemId: $item->id,
            unitPrice: 10,
            taxAmount: 1.50,
            totalAmount: 11.50,
        ));

        $item->update(['price' => 12]);
        $this->setTaxRate($workspace, 25);
        $order = Order::query()->where('client_reference', 'tw-frozen-cash')->firstOrFail();

        $this->push($token, $workspace, 'POS-2C2', $this->invoiceOperation(
            id: 'op-frozen-cash-invoice',
            reference: 'tw-frozen-cash',
            orderId: (int) $order->id,
            totalAmount: 11.50,
        ), register: false);

        $order->refresh();
        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame(10.0, (float) $order->items->first()->unit_price);
        $this->assertSame(11.50, (float) $order->total_amount);
        $this->assertSame(11.50, (float) $invoice->total_amount);
        $this->assertSame((float) $order->total_amount, (float) $invoice->total_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('cash', $order->metadata['payment_method'] ?? null);
        $this->assertNotSame(12.0, (float) $order->items->first()->unit_price);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_retry_order_and_invoice_keep_one_paid_cash_state(): void
    {
        [$token, $workspace, $item, $product] = $this->bootCashierWithInventory();

        $orderOp = $this->orderOperation(
            id: 'op-retry-order',
            reference: 'tw-retry-cash',
            itemId: $item->id,
            unitPrice: 5,
            taxAmount: 1.50,
            totalAmount: 11.50,
            quantity: 2,
        );

        $firstOrder = $this->push($token, $workspace, 'POS-2C2', $orderOp);
        $this->assertSame('applied', $firstOrder['accepted'][0]['status']);
        $order = Order::query()->where('client_reference', 'tw-retry-cash')->firstOrFail();

        $invoiceOp = $this->invoiceOperation(
            id: 'op-retry-invoice',
            reference: 'tw-retry-cash',
            orderId: (int) $order->id,
            totalAmount: 11.50,
        );
        $firstInvoice = $this->push($token, $workspace, 'POS-2C2', $invoiceOp, register: false);
        $this->assertSame('applied', $firstInvoice['accepted'][0]['status']);

        $retryOrder = $this->push($token, $workspace, 'POS-2C2', $orderOp, register: false);
        $this->assertSame('duplicate', $retryOrder['accepted'][0]['status']);
        $this->assertSame($order->id, $retryOrder['accepted'][0]['entity_id']);

        $retryInvoice = $this->push($token, $workspace, 'POS-2C2', $invoiceOp, register: false);
        $this->assertSame('duplicate', $retryInvoice['accepted'][0]['status']);
        $this->assertSame($firstInvoice['accepted'][0]['entity_id'], $retryInvoice['accepted'][0]['entity_id']);

        $this->assertSame(1, Order::query()->where('client_reference', 'tw-retry-cash')->count());
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->where('type', 'remove')
            ->count());
        $this->assertSame(18, (int) $product->fresh()->stock);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('cash', $order->metadata['payment_method'] ?? null);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-retry-order')->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-retry-invoice')->count());
    }

    public function test_duplicate_batch_does_not_create_a_second_cash_state(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);
        $this->registerDevice($token, $workspace, 'POS-2C2');

        $orderOp = $this->orderOperation(
            id: 'op-batch-order',
            reference: 'tw-batch-cash',
            itemId: $item->id,
        );
        $this->push($token, $workspace, 'POS-2C2', $orderOp, register: false);
        $order = Order::query()->where('client_reference', 'tw-batch-cash')->firstOrFail();
        $invoiceOp = $this->invoiceOperation(
            id: 'op-batch-invoice',
            reference: 'tw-batch-cash',
            orderId: (int) $order->id,
        );
        $this->push($token, $workspace, 'POS-2C2', $invoiceOp, register: false);

        $duplicate = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-2C2',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-2C2',
                'operations' => [$orderOp, $invoiceOp],
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($duplicate['success']);
        $this->assertSame('duplicate', $duplicate['accepted'][0]['status']);
        $this->assertSame('duplicate', $duplicate['accepted'][1]['status']);
        $this->assertSame('op-batch-order', $duplicate['accepted'][0]['id']);
        $this->assertSame('op-batch-invoice', $duplicate['accepted'][1]['id']);

        $order->refresh();
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('cash', $order->metadata['payment_method'] ?? null);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem}
     */
    private function bootCashier(float $catalogPrice): array
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => $catalogPrice,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$token, $workspace, $item];
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: Product}
     */
    private function bootCashierWithInventory(): array
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سكر',
            'slug' => 'sugar-2c2',
            'sku' => 'SUGAR-2C2',
            'price' => 4,
            'currency' => 'SAR',
            'stock' => 20,
            'status' => 'active',
        ]);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'name' => 'سكر',
            'price' => 12,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$token, $workspace, $item, $product];
    }

    /**
     * @return array{0: string, 1: Workspace}
     */
    private function bootCashierWorkspace(): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->setTaxRate($workspace, 15);
        $token = $this->loginToken($owner);

        return [$token, $workspace];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderOperation(
        string $id,
        string $reference,
        int $itemId,
        float $unitPrice = 10,
        float $taxAmount = 1.50,
        float $totalAmount = 11.50,
        int $quantity = 1,
    ): array {
        return [
            'id' => $id,
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => $reference,
                'currency' => 'SAR',
                'subtotal_amount' => $unitPrice * $quantity,
                'discount_amount' => 0,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'items' => [[
                    'pos_menu_item_id' => $itemId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'name' => 'شاي مجمد',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceOperation(
        string $id,
        string $reference,
        int $orderId,
        float $totalAmount = 11.50,
    ): array {
        return [
            'id' => $id,
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => $reference,
                'order_server_id' => $orderId,
                'local_invoice_number' => 'INV-000001',
                'currency' => 'SAR',
                'total_amount' => $totalAmount,
                'payment_method' => 'cash',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function push(
        string $token,
        Workspace $workspace,
        string $deviceId,
        array $operation,
        bool $register = true,
    ): array {
        if ($register) {
            $this->registerDevice($token, $workspace, $deviceId);
        }

        return $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => $deviceId,
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => $deviceId,
                'operations' => [$operation],
            ])
            ->assertOk()
            ->json('data');
    }

    private function setTaxRate(Workspace $workspace, float $rate): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $pos = is_array($settings['pos'] ?? null) ? $settings['pos'] : [];
        $pos['tax_rate'] = $rate;
        $settings['pos'] = $pos;
        $workspace->update(['settings' => $settings]);
    }

    private function registerDevice(string $token, Workspace $workspace, string $deviceId): void
    {
        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => $deviceId,
                'name' => $deviceId,
                'platform' => 'cashier',
            ])
            ->assertOk();
    }

    private function loginToken(User $owner): string
    {
        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk();

        return (string) $login->json('data.token');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            \App\Models\WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = \App\Models\Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();

        if ($plan) {
            \App\Models\Subscription::withoutGlobalScopes()->updateOrCreate(
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
}
