<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierFinalPhaseInvoiceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_cash_invoice_lands_in_reports_once(): void
    {
        [$token, $workspace, $item] = $this->bootCashier();
        $soldAt = '2026-09-04T18:15:00+00:00';

        $orderPush = $this->push($token, $workspace, 'POS-FINAL', $this->orderOperation(
            id: 'op-delivery-order',
            reference: 'delivery-final-1',
            itemId: $item->id,
            orderType: 'delivery',
            placedAt: $soldAt,
        ));
        $this->assertTrue($orderPush['success']);

        $order = Order::query()->where('client_reference', 'delivery-final-1')->firstOrFail();
        $this->assertSame('delivery', $order->order_type);
        $this->assertSame('2026-09-04', $order->placed_at?->toDateString());
        $this->assertSame(10.0, (float) $order->items()->firstOrFail()->unit_price);

        $invoicePush = $this->push($token, $workspace, 'POS-FINAL', $this->invoiceOperation(
            id: 'op-delivery-invoice',
            reference: 'delivery-final-1',
            orderId: (int) $order->id,
            orderType: 'delivery',
            closedAt: $soldAt,
        ), register: false);
        $this->assertTrue($invoicePush['success']);
        $this->assertSame('cash', $invoicePush['accepted'][0]['result']['payment_method']);
        $this->assertSame('paid', $invoicePush['accepted'][0]['result']['payment_status']);

        $order->refresh();
        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('cash', $order->metadata['payment_method'] ?? null);
        $this->assertSame('CASH-', substr((string) $invoice->invoice_number, 0, 5));
        $this->assertSame('INV-000001', $invoice->metadata['local_invoice_number'] ?? null);
        $this->assertSame('2026-09-04', $invoice->closed_at?->toDateString());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());

        $sameDay = $this->report($token, $workspace, ['date' => '2026-09-04']);
        $this->assertSame(1, (int) $sameDay['summary']['invoices_count']);
        $this->assertEqualsWithDelta(11.50, (float) $sameDay['summary']['invoices_total'], 0.001);
        $this->assertEqualsWithDelta(11.50, (float) $sameDay['summary']['cash_sales_total'], 0.001);
        $this->assertSame(1, (int) $sameDay['summary']['delivery_orders_count']);
        $this->assertSame('شاي مجمد', $sameDay['top_items'][0]['product_name'] ?? null);
        $this->assertNotEmpty($sameDay['sales_by_category']);
        $this->assertSame('2026-09-04', $sameDay['sales_by_day'][0]['date'] ?? null);

        $syncDay = $this->report($token, $workspace, ['date' => now()->toDateString()]);
        if (now()->toDateString() !== '2026-09-04') {
            $this->assertSame(0, (int) $syncDay['summary']['invoices_count']);
        }

        $retry = $this->push($token, $workspace, 'POS-FINAL', $this->invoiceOperation(
            id: 'op-delivery-invoice',
            reference: 'delivery-final-1',
            orderId: (int) $order->id,
            orderType: 'delivery',
            closedAt: $soldAt,
        ), register: false);
        $this->assertSame('duplicate', $retry['accepted'][0]['status']);
        $this->assertSame(1, PosCashierInvoice::query()->count());

        $afterRetry = $this->report($token, $workspace, ['date' => '2026-09-04']);
        $this->assertSame(1, (int) $afterRetry['summary']['invoices_count']);
        $this->assertEqualsWithDelta(11.50, (float) $afterRetry['summary']['cash_sales_total'], 0.001);
    }

    public function test_takeaway_table_and_delivery_share_one_report_range_without_duplicates(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable();

        foreach ([
            ['ref' => 'tw-range-1', 'type' => 'takeaway', 'table' => null, 'day' => '2026-09-03'],
            ['ref' => 'tb-range-1', 'type' => 'table', 'table' => $table->id, 'day' => '2026-09-04'],
            ['ref' => 'dl-range-1', 'type' => 'delivery', 'table' => null, 'day' => '2026-09-05'],
        ] as $sale) {
            $this->push($token, $workspace, 'POS-FINAL', $this->orderOperation(
                id: 'op-'.$sale['ref'],
                reference: $sale['ref'],
                itemId: $item->id,
                tableId: $sale['table'],
                orderType: $sale['type'],
                placedAt: $sale['day'].'T12:00:00+00:00',
            ), register: $sale['ref'] === 'tw-range-1');
            $order = Order::query()->where('client_reference', $sale['ref'])->firstOrFail();
            $this->push($token, $workspace, 'POS-FINAL', $this->invoiceOperation(
                id: 'inv-'.$sale['ref'],
                reference: $sale['ref'],
                orderId: (int) $order->id,
                orderType: $sale['type'],
                closedAt: $sale['day'].'T12:00:00+00:00',
            ), register: false);
        }

        $this->assertSame(3, PosCashierInvoice::query()->count());

        $range = $this->report($token, $workspace, [
            'from' => '2026-09-03',
            'to' => '2026-09-05',
        ]);
        $this->assertSame(3, (int) $range['summary']['invoices_count']);
        $this->assertEqualsWithDelta(34.50, (float) $range['summary']['invoices_total'], 0.001);
        $this->assertEqualsWithDelta(34.50, (float) $range['summary']['cash_sales_total'], 0.001);
        $this->assertSame(1, (int) $range['summary']['takeaway_orders_count']);
        $this->assertSame(1, (int) $range['summary']['table_orders_count']);
        $this->assertSame(1, (int) $range['summary']['delivery_orders_count']);
        $this->assertCount(3, $range['sales_by_day']);

        $oneDay = $this->report($token, $workspace, ['date' => '2026-09-04']);
        $this->assertSame(1, (int) $oneDay['summary']['invoices_count']);
        $this->assertSame(1, (int) $oneDay['summary']['table_orders_count']);
        $this->assertSame(0, (int) $oneDay['summary']['delivery_orders_count']);
    }

    public function test_catalog_price_change_does_not_reprice_historical_delivery_invoice(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);

        $this->push($token, $workspace, 'POS-FINAL', $this->orderOperation(
            id: 'op-frozen-delivery-order',
            reference: 'delivery-frozen',
            itemId: $item->id,
            orderType: 'delivery',
            unitPrice: 10,
            taxAmount: 1.50,
            totalAmount: 11.50,
        ));
        $item->update(['price' => 12]);
        $order = Order::query()->where('client_reference', 'delivery-frozen')->firstOrFail();
        $this->push($token, $workspace, 'POS-FINAL', $this->invoiceOperation(
            id: 'op-frozen-delivery-invoice',
            reference: 'delivery-frozen',
            orderId: (int) $order->id,
            orderType: 'delivery',
            totalAmount: 11.50,
        ), register: false);

        $order->refresh();
        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame(11.50, (float) $order->total_amount);
        $this->assertSame(11.50, (float) $invoice->total_amount);
        $this->assertSame(10.0, (float) $order->items()->firstOrFail()->unit_price);
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: DiningTable}
     */
    private function bootOneTable(float $catalogPrice = 10): array
    {
        [$token, $workspace, $item] = $this->bootCashier($catalogPrice);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 1',
            'status' => 'available',
            'qr_token' => 'qr-final-1',
        ]);

        return [$token, $workspace, $item, $table];
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem}
     */
    private function bootCashier(float $catalogPrice = 10): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->setTaxRate($workspace, 15);
        $token = $this->loginToken($owner);
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
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function report(string $token, Workspace $workspace, array $query): array
    {
        return $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-FINAL',
            ])
            ->getJson('/api/cashier/v1/reports/daily?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function orderOperation(
        string $id,
        string $reference,
        int $itemId,
        ?int $tableId = null,
        float $unitPrice = 10,
        float $taxAmount = 1.50,
        float $totalAmount = 11.50,
        string $orderType = 'delivery',
        ?string $placedAt = null,
    ): array {
        return [
            'id' => $id,
            'type' => 'order.created',
            'data' => [
                'order_type' => $orderType,
                'offline_sale' => true,
                'client_reference' => $reference,
                'currency' => 'SAR',
                'subtotal_amount' => $unitPrice,
                'discount_amount' => 0,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                ...($tableId !== null ? ['dining_table_id' => $tableId] : []),
                ...($placedAt !== null ? ['placed_at' => $placedAt] : []),
                'items' => [[
                    'pos_menu_item_id' => $itemId,
                    'quantity' => 1,
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
        string $orderType = 'delivery',
        ?string $closedAt = null,
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
                ...($closedAt !== null ? ['closed_at' => $closedAt] : []),
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
            $this->withToken($token)
                ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
                ->postJson('/api/cashier/v1/devices/register', [
                    'device_id' => $deviceId,
                    'name' => $deviceId,
                    'platform' => 'cashier',
                ])
                ->assertOk();
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
