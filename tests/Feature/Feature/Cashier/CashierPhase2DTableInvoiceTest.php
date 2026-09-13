<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\Finance\FinanceInvoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPhase2DTableInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_table_sale_does_not_open_or_merge_a_live_session(): void
    {
        [$token, $workspace, $item, $tableA, $tableB] = $this->bootTables();

        $first = $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-table-a',
            reference: 'table-a-ref',
            itemId: $item->id,
            tableId: $tableA->id,
        ));
        $this->assertTrue($first['success']);

        $second = $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-table-b',
            reference: 'table-b-ref',
            itemId: $item->id,
            tableId: $tableB->id,
        ), register: false);
        $this->assertTrue($second['success']);

        $sameTable = $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-table-a2',
            reference: 'table-a-ref-2',
            itemId: $item->id,
            tableId: $tableA->id,
            unitPrice: 10,
            taxAmount: 1.50,
            totalAmount: 11.50,
        ), register: false);
        $this->assertTrue($sameTable['success']);

        $this->assertSame(0, TableSession::withoutGlobalScopes()->count());
        $this->assertSame(3, Order::query()->whereIn('client_reference', [
            'table-a-ref',
            'table-b-ref',
            'table-a-ref-2',
        ])->count());

        $orderA = Order::query()->where('client_reference', 'table-a-ref')->firstOrFail();
        $orderB = Order::query()->where('client_reference', 'table-b-ref')->firstOrFail();
        $orderA2 = Order::query()->where('client_reference', 'table-a-ref-2')->firstOrFail();

        $this->assertSame($tableA->id, $orderA->dining_table_id);
        $this->assertSame($tableB->id, $orderB->dining_table_id);
        $this->assertSame($tableA->id, $orderA2->dining_table_id);
        $this->assertNull($orderA->table_session_id);
        $this->assertNull($orderB->table_session_id);
        $this->assertSame('table', $orderA->order_type);
        $this->assertTrue((bool) ($orderA->metadata['offline_sale'] ?? false));
        $this->assertNotSame($orderA->id, $orderA2->id);
    }

    public function test_table_invoice_uses_cash_paid_snapshot_without_a_payment_row(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable(catalogPrice: 12);

        $orderPush = $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-table-cash-order',
            reference: 'table-cash-1',
            itemId: $item->id,
            tableId: $table->id,
            unitPrice: 10,
            taxAmount: 0.90,
            totalAmount: 10.90,
        ));
        $this->assertTrue($orderPush['success']);

        $order = Order::query()->where('client_reference', 'table-cash-1')->firstOrFail();
        $this->assertSame(10.90, (float) $order->total_amount);
        $this->assertSame('pending', $order->payment_status);

        $invoicePush = $this->push($token, $workspace, 'POS-2D', $this->invoiceOperation(
            id: 'op-table-cash-invoice',
            reference: 'table-cash-1',
            orderId: (int) $order->id,
            totalAmount: 10.90,
        ), register: false);
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
        $this->assertSame(10.90, (float) $order->total_amount);
        $this->assertSame(10.90, (float) $invoice->total_amount);
        $this->assertStringStartsWith('CASH-', (string) $invoice->invoice_number);
        $this->assertSame('INV-000001', $invoice->metadata['local_invoice_number'] ?? null);
        $this->assertSame(0, TableSession::withoutGlobalScopes()->count());
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_retry_does_not_duplicate_table_order_invoice_or_report_sale(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable();

        $orderOp = $this->orderOperation(
            id: 'op-table-dup-order',
            reference: 'table-dup-1',
            itemId: $item->id,
            tableId: $table->id,
        );
        $this->push($token, $workspace, 'POS-2D', $orderOp);
        $order = Order::query()->where('client_reference', 'table-dup-1')->firstOrFail();
        $invoiceOp = $this->invoiceOperation(
            id: 'op-table-dup-invoice',
            reference: 'table-dup-1',
            orderId: (int) $order->id,
        );
        $this->push($token, $workspace, 'POS-2D', $invoiceOp, register: false);

        $retryOrder = $this->push($token, $workspace, 'POS-2D', $orderOp, register: false);
        $retryInvoice = $this->push($token, $workspace, 'POS-2D', $invoiceOp, register: false);
        $this->assertSame('duplicate', $retryOrder['accepted'][0]['status']);
        $this->assertSame('duplicate', $retryInvoice['accepted'][0]['status']);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-table-dup-order')->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-table-dup-invoice')->count());

        $report = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-2D',
            ])
            ->getJson('/api/cashier/v1/reports/daily')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, (int) $report['summary']['invoices_count']);
        $this->assertEqualsWithDelta(11.50, (float) $report['summary']['invoices_total'], 0.001);
        $this->assertSame(1, (int) $report['summary']['table_orders_count']);
    }

    public function test_takeaway_and_table_invoices_both_appear_once_in_reports(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable();

        $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-tw-order',
            reference: 'tw-report-1',
            itemId: $item->id,
            tableId: null,
            orderType: 'takeaway',
            offlineSale: true,
        ));
        $takeaway = Order::query()->where('client_reference', 'tw-report-1')->firstOrFail();
        $this->push($token, $workspace, 'POS-2D', $this->invoiceOperation(
            id: 'op-tw-invoice',
            reference: 'tw-report-1',
            orderId: (int) $takeaway->id,
            orderType: 'takeaway',
        ), register: false);

        $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-tb-order',
            reference: 'tb-report-1',
            itemId: $item->id,
            tableId: $table->id,
        ), register: false);
        $tableOrder = Order::query()->where('client_reference', 'tb-report-1')->firstOrFail();
        $this->push($token, $workspace, 'POS-2D', $this->invoiceOperation(
            id: 'op-tb-invoice',
            reference: 'tb-report-1',
            orderId: (int) $tableOrder->id,
        ), register: false);

        $this->assertSame(2, PosCashierInvoice::query()->count());
        $this->assertSame(2, Order::query()->whereNotNull('pos_cashier_invoice_id')->count());

        $report = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-2D',
            ])
            ->getJson('/api/cashier/v1/reports/daily')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, (int) $report['summary']['invoices_count']);
        $this->assertEqualsWithDelta(23.0, (float) $report['summary']['invoices_total'], 0.001);
        $this->assertSame(1, (int) $report['summary']['takeaway_orders_count']);
        $this->assertSame(1, (int) $report['summary']['table_orders_count']);
    }

    public function test_catalog_price_change_does_not_reprice_offline_table_sale(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable(catalogPrice: 10);

        $this->push($token, $workspace, 'POS-2D', $this->orderOperation(
            id: 'op-frozen-table-order',
            reference: 'table-frozen',
            itemId: $item->id,
            tableId: $table->id,
            unitPrice: 10,
            taxAmount: 1.50,
            totalAmount: 11.50,
        ));

        $item->update(['price' => 12]);

        $order = Order::query()->where('client_reference', 'table-frozen')->firstOrFail();
        $this->push($token, $workspace, 'POS-2D', $this->invoiceOperation(
            id: 'op-frozen-table-invoice',
            reference: 'table-frozen',
            orderId: (int) $order->id,
            totalAmount: 11.50,
        ), register: false);

        $order->refresh();
        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame(11.50, (float) $order->total_amount);
        $this->assertSame(11.50, (float) $invoice->total_amount);
        $this->assertSame(10.0, (float) $order->items()->firstOrFail()->unit_price);
    }

    public function test_live_web_pos_table_order_without_offline_sale_still_opens_a_session(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable();

        $push = $this->push($token, $workspace, 'POS-2D', [
            'id' => 'op-live-table',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'dining_table_id' => $table->id,
                'client_reference' => 'live-web-pos',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                ]],
            ],
        ]);
        $this->assertTrue($push['success']);

        $order = Order::query()->where('client_reference', 'live-web-pos')->firstOrFail();
        $this->assertNotNull($order->table_session_id);
        $this->assertSame(1, TableSession::withoutGlobalScopes()->count());
        $this->assertSame('open', TableSession::withoutGlobalScopes()->firstOrFail()->status);
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: DiningTable, 4: DiningTable}
     */
    private function bootTables(): array
    {
        [$token, $workspace, $item] = $this->bootCashier();
        $tableA = $this->makeTable($workspace, 'طاولة أ', 'qr-2d-a');
        $tableB = $this->makeTable($workspace, 'طاولة ب', 'qr-2d-b');

        return [$token, $workspace, $item, $tableA, $tableB];
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: DiningTable}
     */
    private function bootOneTable(float $catalogPrice = 10): array
    {
        [$token, $workspace, $item] = $this->bootCashier($catalogPrice);
        $table = $this->makeTable($workspace, 'طاولة 1', 'qr-2d-1');

        return [$token, $workspace, $item, $table];
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem}
     */
    private function bootCashier(float $catalogPrice = 10): array
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

    private function makeTable(Workspace $workspace, string $name, string $qrToken): DiningTable
    {
        return DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'available',
            'qr_token' => $qrToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderOperation(
        string $id,
        string $reference,
        int $itemId,
        ?int $tableId,
        float $unitPrice = 10,
        float $taxAmount = 1.50,
        float $totalAmount = 11.50,
        int $quantity = 1,
        string $orderType = 'table',
        bool $offlineSale = true,
    ): array {
        return [
            'id' => $id,
            'type' => 'order.created',
            'data' => [
                'order_type' => $orderType,
                'offline_sale' => $offlineSale,
                'client_reference' => $reference,
                'currency' => 'SAR',
                'subtotal_amount' => $unitPrice * $quantity,
                'discount_amount' => 0,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                ...($tableId !== null ? ['dining_table_id' => $tableId] : []),
                'items' => [[
                    'pos_menu_item_id' => $itemId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_amount' => $taxAmount,
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
        string $orderType = 'table',
    ): array {
        return [
            'id' => $id,
            'type' => 'invoice.created',
            'data' => [
                'order_type' => $orderType,
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
