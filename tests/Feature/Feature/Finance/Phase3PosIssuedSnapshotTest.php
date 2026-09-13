<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\IssuedSnapshotBuilder;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class Phase3PosIssuedSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_issued_pos_invoice_creates_exactly_one_snapshot_with_authoritative_payload(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'POS Buyer', [
            'vat_number' => '300111111111113',
            'address' => 'Riyadh',
            'phone' => '0500000001',
            'email' => 'pos-buyer@example.com',
        ]);
        $this->updateCompany($workspace, [
            'company_name' => 'POS Seller Co',
            'vat_number' => '310000000000003',
            'city' => 'Jeddah',
        ]);
        $tea = $this->menuItem($workspace, 'Service A', 80);
        $cake = $this->menuItem($workspace, 'Cake', 20);

        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $tea->id, 'quantity' => 1],
            ['pos_menu_item_id' => $cake->id, 'quantity' => 1],
        ], $customer);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);

        $snapshots = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->get();
        $this->assertCount(1, $snapshots);

        $snapshot = $snapshots->first();
        $this->assertSame($workspace->id, (int) $snapshot->workspace_id);
        $this->assertSame($invoice->invoice_number, $snapshot->document_number);
        $this->assertStringStartsWith('CASH-', (string) $snapshot->document_number);
        $this->assertSame('SAR', $snapshot->currency);
        $this->assertSame($invoice->invoice_number, data_get($snapshot->payload, 'document.number'));
        $this->assertSame('closed', data_get($snapshot->payload, 'document.status'));
        $this->assertSame('POS Seller Co', data_get($snapshot->payload, 'seller.company_name'));
        $this->assertSame('310000000000003', data_get($snapshot->payload, 'seller.vat_number'));
        $this->assertSame($workspace->id, (int) data_get($snapshot->payload, 'seller.workspace_id'));
        $this->assertSame('POS Buyer', data_get($snapshot->payload, 'buyer.name'));
        $this->assertSame('300111111111113', data_get($snapshot->payload, 'buyer.vat_number'));
        $this->assertSame('customer', data_get($snapshot->payload, 'buyer.kind'));
        $this->assertCount(2, data_get($snapshot->payload, 'lines'));
        $this->assertSame('Service A', data_get($snapshot->payload, 'lines.0.product_name'));
        $this->assertSame('Cake', data_get($snapshot->payload, 'lines.1.product_name'));
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.amount'));
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.configured_rate'));
        $this->assertSame('pos', data_get($snapshot->payload, 'tax.engine'));
        $this->assertSame('100.00', data_get($snapshot->payload, 'totals.subtotal'));
        $this->assertSame('0.00', data_get($snapshot->payload, 'totals.discount'));
        $this->assertSame('100.00', data_get($snapshot->payload, 'totals.taxable_amount'));
        $this->assertSame('15.00', data_get($snapshot->payload, 'totals.tax_amount'));
        $this->assertSame('115.00', data_get($snapshot->payload, 'totals.total'));
        $this->assertSame((float) $order->fresh()->tax_amount, (float) data_get($snapshot->payload, 'totals.tax_amount'));
        $this->assertSame((float) $invoice->total_amount, (float) data_get($snapshot->payload, 'totals.total'));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
    }

    public function test_anonymous_pos_sale_snapshots_walk_in_buyer_honestly(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Walk-in Tea', 10);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);

        $this->assertSame('anonymous', data_get($snapshot->payload, 'buyer.kind'));
        $this->assertTrue((bool) data_get($snapshot->payload, 'buyer.walk_in'));
        $this->assertNull(data_get($snapshot->payload, 'buyer.name'));
        $this->assertNull(data_get($snapshot->payload, 'buyer.vat_number'));
    }

    public function test_order_creation_does_not_create_a_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Open Ticket', 12);
        $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);

        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
        $this->assertSame(0, PosCashierInvoice::withoutGlobalScopes()->count());
    }

    public function test_closing_a_table_session_creates_one_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Table Tea', 10);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'T-1',
            'status' => 'available',
            'qr_token' => 'phase3-table-token',
        ]);

        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'dining_table_id' => $table->id,
            'order_type' => 'table',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());

        $invoice = app(PosOrderService::class)->closeSession(
            $order->fresh()->tableSession,
            (int) $owner->id,
            'cash',
        );

        $this->assertNotNull($invoice);
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->count());
        $snapshot = $this->posSnapshot($invoice);
        $this->assertSame('paid', data_get($snapshot->payload, 'payment.payment_status'));
        $this->assertSame(['cash'], data_get($snapshot->payload, 'payment.payment_methods'));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_product_change_after_issue_does_not_change_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Service A', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);

        $item->update(['name' => 'Service B', 'price' => 9]);

        $fresh = $snapshot->fresh();
        $this->assertSame('Service A', data_get($fresh->payload, 'lines.0.product_name'));
        $this->assertSame('Service A', data_get($fresh->payload, 'lines.0.description'));
        $this->assertSame($snapshot->payload, $fresh->payload);
    }

    public function test_customer_change_after_issue_does_not_change_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Frozen Buyer', ['vat_number' => '300222222222223']);
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ], $customer);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);

        $customer->update(['name' => 'Changed Buyer', 'vat_number' => '399999999999993']);

        $fresh = $snapshot->fresh();
        $this->assertSame('Frozen Buyer', data_get($fresh->payload, 'buyer.name'));
        $this->assertSame('300222222222223', data_get($fresh->payload, 'buyer.vat_number'));
        $this->assertSame($snapshot->payload, $fresh->payload);
    }

    public function test_changing_pos_invoice_after_issue_does_not_change_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $before = $this->posSnapshot($invoice)->payload;

        $invoice->update([
            'subtotal' => 1,
            'discount_amount' => 0,
            'total_amount' => 1,
        ]);
        app(PosOrderService::class)->ensureIssuedSnapshot($invoice->fresh(['items', 'orders']));

        $this->assertSame($before, $this->posSnapshot($invoice->fresh())->payload);
        $this->assertSame('115.00', data_get($before, 'totals.total'));
    }

    public function test_snapshot_capture_and_invoice_retry_are_idempotent(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ], clientReference: 'pos-offline-uuid-1');

        $firstInvoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $replayedOrder = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'client_reference' => 'pos-offline-uuid-1',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $secondInvoice = app(PosOrderService::class)->createInvoiceFromOrder($replayedOrder, (int) $owner->id);
        $first = app(IssuedSnapshotBuilder::class)->capturePosCashierInvoice(
            $firstInvoice->fresh(['items', 'orders.customer'])
        );
        $second = app(IssuedSnapshotBuilder::class)->capturePosCashierInvoice(
            $secondInvoice->fresh(['items', 'orders.customer'])
        );

        $this->assertSame($order->id, $replayedOrder->id);
        $this->assertSame($firstInvoice->id, $secondInvoice->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PosCashierInvoice::withoutGlobalScopes()->count());
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->count());
    }

    public function test_database_uniqueness_prevents_duplicate_pos_snapshots(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $existing = $this->posSnapshot($invoice);

        $this->expectException(UniqueConstraintViolationException::class);
        IssuedDocumentSnapshot::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'source_type' => IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
            'source_id' => $invoice->id,
            'document_number' => $existing->document_number.'-DUP',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'payload' => ['document' => ['number' => 'dup']],
        ]);
    }

    public function test_cross_workspace_pos_snapshot_creation_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspaceA, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspaceA, $ownerA, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $ownerA->id);
        $this->createWorkspaceOwner();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-workspace snapshot creation is not allowed.');
        app(IssuedSnapshotBuilder::class)->capturePosCashierInvoice(
            PosCashierInvoice::withoutGlobalScopes()
                ->with(['items', 'orders.customer'])
                ->findOrFail($invoice->id)
        );
    }

    public function test_pos_snapshot_does_not_create_finance_invoice_or_gl_and_finance_issue_still_works(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $posInvoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);

        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->count());

        $customer = $this->makeCustomer($workspace, 'Finance Buyer');
        $financeInvoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $owner->id);

        $this->assertSame('issued', $financeInvoice->invoice_status);
        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $financeInvoice->id)
            ->count());
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $financeInvoice->id)
            ->count());
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $posInvoice->id)
            ->count());
        $this->assertNull($order->fresh()->finance_invoice_id);
    }

    public function test_cashier_sync_retry_does_not_duplicate_issued_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'شاي', 10);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-P3');

        $orderPush = $this->push($token, $workspace, 'POS-P3', [
            'id' => 'op-order-p3',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'tw-p3-inv',
                'offline_sale' => true,
                'currency' => 'SAR',
                'subtotal_amount' => 10,
                'discount_amount' => 0,
                'tax_amount' => 1.50,
                'total_amount' => 11.50,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'name' => 'شاي مجمد',
                ]],
            ],
        ]);
        $this->assertTrue($orderPush['success']);
        $order = Order::query()->where('client_reference', 'tw-p3-inv')->firstOrFail();
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());

        $invoiceOp = [
            'id' => 'op-invoice-p3',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-p3-inv',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000001',
                'payment_method' => 'cash',
                'tax_amount' => 1.50,
                'total_amount' => 11.50,
            ],
        ];
        $first = $this->push($token, $workspace, 'POS-P3', $invoiceOp);
        $retry = $this->push($token, $workspace, 'POS-P3', $invoiceOp);

        $this->assertTrue($first['success']);
        $this->assertTrue($retry['success']);
        $this->assertSame('duplicate', $retry['accepted'][0]['status']);
        $this->assertSame(1, PosCashierInvoice::withoutGlobalScopes()->count());
        $invoice = PosCashierInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->count());
        $snapshot = $this->posSnapshot($invoice);
        $this->assertSame($invoice->invoice_number, data_get($snapshot->payload, 'document.number'));
        $this->assertSame('1.50', data_get($snapshot->payload, 'totals.tax_amount'));
        $this->assertSame('11.50', data_get($snapshot->payload, 'totals.total'));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_snapshot_failure_rolls_back_pos_invoice_issue(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);

        $this->app->bind(IssuedSnapshotBuilder::class, fn () => new class extends IssuedSnapshotBuilder
        {
            public function capturePosCashierInvoice(PosCashierInvoice $invoice): IssuedDocumentSnapshot
            {
                throw new RuntimeException('snapshot persist failed');
            }
        });

        try {
            app(PosOrderService::class)->createInvoiceFromOrder($order->fresh(), (int) $owner->id);
            $this->fail('POS invoice succeeded despite snapshot failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('snapshot persist failed', $exception->getMessage());
        }

        $this->assertNull($order->fresh()->pos_cashier_invoice_id);
        $this->assertSame(0, PosCashierInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'store',
            'name' => 'POS Workspace',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'pos', 'qr_menu', 'products', 'orders', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()->where('workspace_type', 'store')->where('is_active', true)->orderByDesc('price')->first()
            ?? Plan::query()->where('is_active', true)->orderByDesc('price')->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        app(WorkspaceContext::class)->set($workspace);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $this->setTaxRate($workspace, 15);

        return [$user, $workspace->fresh()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCustomer(Workspace $workspace, string $name, array $attributes = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateCompany(Workspace $workspace, array $attributes): void
    {
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update($attributes);
    }

    private function menuItem(Workspace $workspace, string $name, float $price): PosMenuItem
    {
        return PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'item_type' => 'خدمات',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createTakeawayOrder(
        Workspace $workspace,
        User $owner,
        array $items,
        ?Customer $customer = null,
        ?string $clientReference = null,
    ): Order {
        $payload = [
            'order_type' => 'takeaway',
            'items' => $items,
        ];
        if ($customer) {
            $payload['customer_id'] = $customer->id;
        }
        if ($clientReference) {
            $payload['client_reference'] = $clientReference;
        }

        return app(PosOrderService::class)->createPosOrder($workspace, $payload, $owner);
    }

    private function posSnapshot(PosCashierInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }

    private function setTaxRate(Workspace $workspace, float $rate): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $pos = is_array($settings['pos'] ?? null) ? $settings['pos'] : [];
        $pos['tax_rate'] = $rate;
        $settings['pos'] = $pos;
        $workspace->update(['settings' => $settings]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function push(string $token, Workspace $workspace, string $deviceId, array $operation): array
    {
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
}
