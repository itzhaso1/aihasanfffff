<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Finance\FinanceInvoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPhase2C1TakeawayInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_sync_then_invoice_sync_returns_cash_number_from_order_snapshot(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 12);

        $orderPush = $this->push($token, $workspace, 'POS-2C1', $this->orderOperation(
            id: 'op-order-1',
            reference: 'tw-inv-1',
            itemId: $item->id,
            unitPrice: 10,
            taxAmount: 0.90,
            totalAmount: 10.90,
        ));

        $this->assertTrue($orderPush['success']);
        $order = Order::query()->where('client_reference', 'tw-inv-1')->firstOrFail();
        $this->assertNull($order->pos_cashier_invoice_id);

        $invoicePush = $this->push($token, $workspace, 'POS-2C1', [
            'id' => 'op-invoice-1',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-inv-1',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000001',
                'currency' => 'SAR',
                'subtotal_amount' => 10.00,
                'discount_amount' => 0,
                'tax_amount' => 0.90,
                'total_amount' => 10.90,
                'payment_method' => 'cash',
            ],
        ], register: false);

        $this->assertTrue($invoicePush['success']);
        $ack = $invoicePush['accepted'][0];
        $this->assertSame('applied', $ack['status']);
        $this->assertSame('invoice.created', $ack['type']);
        $this->assertNotEmpty($ack['result']['invoice_number']);
        $this->assertStringStartsWith('CASH-', (string) $ack['result']['invoice_number']);
        $this->assertSame('INV-000001', $ack['result']['local_invoice_number']);

        $this->assertSame(1, PosCashierInvoice::query()->count());
        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame($invoice->id, $ack['entity_id']);
        $this->assertStringStartsWith('CASH-', $invoice->invoice_number);
        $this->assertSame((float) $order->total_amount, (float) $invoice->total_amount);
        $this->assertSame((float) $order->subtotal, (float) $invoice->subtotal);
        $this->assertSame((float) $order->discount_amount, (float) $invoice->discount_amount);
        $this->assertSame(10.90, (float) $invoice->total_amount);
        $this->assertSame(0.90, (float) $ack['result']['tax_amount']);
        $this->assertSame('INV-000001', $invoice->metadata['local_invoice_number'] ?? null);
        $this->assertSame('cash', $invoice->metadata['payment_method'] ?? null);

        $order->refresh();
        $this->assertSame($invoice->id, $order->pos_cashier_invoice_id);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_catalog_price_change_does_not_reprice_the_cashier_invoice(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);

        $this->push($token, $workspace, 'POS-2C1', $this->orderOperation(
            id: 'op-frozen-order',
            reference: 'tw-frozen-inv',
            itemId: $item->id,
            unitPrice: 10,
            taxAmount: 1.50,
            totalAmount: 11.50,
        ));

        $item->update(['price' => 40]);
        $this->setTaxRate($workspace, 25);
        $order = Order::query()->where('client_reference', 'tw-frozen-inv')->firstOrFail();

        $this->push($token, $workspace, 'POS-2C1', [
            'id' => 'op-frozen-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-frozen-inv',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000009',
                'payment_method' => 'cash',
                'total_amount' => 11.50,
            ],
        ], register: false);

        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame(11.50, (float) $invoice->total_amount);
        $this->assertSame((float) $order->total_amount, (float) $invoice->total_amount);
        $this->assertNotSame(40.0, (float) $invoice->subtotal);
        $this->assertSame(1, PosCashierInvoice::query()->count());
    }

    public function test_same_uuid_retry_is_duplicate_and_does_not_create_a_second_invoice(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);
        $this->push($token, $workspace, 'POS-2C1', $this->orderOperation(
            id: 'op-dup-order',
            reference: 'tw-dup-inv',
            itemId: $item->id,
        ));
        $order = Order::query()->where('client_reference', 'tw-dup-inv')->firstOrFail();

        $invoiceOp = [
            'id' => 'op-dup-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-dup-inv',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000002',
                'payment_method' => 'cash',
            ],
        ];

        $first = $this->push($token, $workspace, 'POS-2C1', $invoiceOp, register: false);
        $this->assertSame('applied', $first['accepted'][0]['status']);
        $invoiceId = $first['accepted'][0]['entity_id'];
        $number = $first['accepted'][0]['result']['invoice_number'];

        $retry = $this->push($token, $workspace, 'POS-2C1', $invoiceOp, register: false);
        $this->assertTrue($retry['success']);
        $this->assertSame('duplicate', $retry['accepted'][0]['status']);
        $this->assertSame($invoiceId, $retry['accepted'][0]['entity_id']);
        $this->assertSame($number, $retry['accepted'][0]['result']['invoice_number']);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('type', 'invoice.created')->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_second_invoice_uuid_for_the_same_order_reuses_the_existing_invoice(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);
        $this->push($token, $workspace, 'POS-2C1', $this->orderOperation(
            id: 'op-idemp-order',
            reference: 'tw-idemp-inv',
            itemId: $item->id,
        ));
        $order = Order::query()->where('client_reference', 'tw-idemp-inv')->firstOrFail();

        $first = $this->push($token, $workspace, 'POS-2C1', [
            'id' => 'op-idemp-invoice-a',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-idemp-inv',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000003',
                'payment_method' => 'cash',
            ],
        ], register: false);

        $second = $this->push($token, $workspace, 'POS-2C1', [
            'id' => 'op-idemp-invoice-b',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-idemp-inv',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000003',
                'payment_method' => 'cash',
            ],
        ], register: false);

        $this->assertSame($first['accepted'][0]['entity_id'], $second['accepted'][0]['entity_id']);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(
            $first['accepted'][0]['result']['invoice_number'],
            $second['accepted'][0]['result']['invoice_number']
        );
    }

    public function test_invoice_created_before_the_order_exists_fails_and_creates_nothing(): void
    {
        [$token, $workspace] = $this->bootCashierWorkspace();

        $push = $this->push($token, $workspace, 'POS-2C1', [
            'id' => 'op-invoice-too-soon',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'missing-order',
                'local_invoice_number' => 'INV-000004',
                'payment_method' => 'cash',
            ],
        ]);

        $this->assertFalse($push['success']);
        $this->assertNotEmpty($push['failed']);
        $this->assertFalse($push['failed'][0]['retryable']);
        $this->assertSame(0, PosCashierInvoice::query()->count());
        $this->assertSame(0, Order::query()->count());
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
    ): array {
        return [
            'id' => $id,
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => $reference,
                'currency' => 'SAR',
                'subtotal_amount' => $unitPrice,
                'discount_amount' => 0,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'items' => [[
                    'pos_menu_item_id' => $itemId,
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'name' => 'شاي مجمد',
                ]],
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
