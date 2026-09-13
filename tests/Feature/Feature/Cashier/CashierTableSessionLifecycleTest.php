<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PosCashierInvoice;
use App\Models\PosCustomerSession;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CashierTableSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_close_without_session_id_still_closes_leftover_open_session(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        [$token, $workspace, $item, $table] = $this->bootTable();

        $open = $this->push($token, $workspace, 'POS-LIFE', [
            'id' => 'op-open-1',
            'type' => 'table_session.open',
            'data' => [
                'table_server_id' => $table->id,
                'session_client_id' => 'sess-one',
            ],
        ]);
        $this->assertTrue($open['success']);
        $firstId = (int) $open['accepted'][0]['result']['session_id'];
        $first = TableSession::withoutGlobalScopes()->findOrFail($firstId);
        $this->assertSame('open', $first->status);
        $openedAt = $first->opened_at?->copy();

        Carbon::setTestNow('2026-09-09 12:05:00');
        $close = $this->push($token, $workspace, 'POS-LIFE', [
            'id' => 'op-close-1',
            'type' => 'table_session.close',
            'data' => [
                'table_server_id' => $table->id,
                'payment_method' => 'cash',
            ],
        ], register: false);
        $this->assertTrue($close['success']);

        $first->refresh();
        $table->refresh();
        $this->assertSame('closed', $first->status);
        $this->assertNotNull($first->closed_at);
        $this->assertSame('available', $table->status);
        $this->assertSame(0, TableSession::withoutGlobalScopes()->where('status', 'open')->count());

        Carbon::setTestNow('2026-09-09 12:06:00');
        $reopen = $this->push($token, $workspace, 'POS-LIFE', [
            'id' => 'op-open-2',
            'type' => 'table_session.open',
            'data' => [
                'table_server_id' => $table->id,
                'session_client_id' => 'sess-two',
            ],
        ], register: false);
        $this->assertTrue($reopen['success']);
        $secondId = (int) $reopen['accepted'][0]['result']['session_id'];
        $this->assertNotSame($firstId, $secondId);
        $second = TableSession::withoutGlobalScopes()->findOrFail($secondId);
        $this->assertSame('open', $second->status);
        $this->assertNotNull($openedAt);
        $this->assertTrue($second->opened_at->gt($openedAt));
        $this->assertSame(
            '2026-09-09 12:06:00',
            $second->opened_at->format('Y-m-d H:i:s')
        );
        unset($item);
    }

    public function test_paid_offline_invoice_is_accepted_while_a_table_session_is_still_open(): void
    {
        [$token, $workspace, $item, $table] = $this->bootTable();

        $this->push($token, $workspace, 'POS-LIFE', [
            'id' => 'op-open-live',
            'type' => 'table_session.open',
            'data' => [
                'table_server_id' => $table->id,
                'session_client_id' => 'sess-live',
            ],
        ]);

        $orderPush = $this->push($token, $workspace, 'POS-LIFE', [
            'id' => 'op-offline-order',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'offline_sale' => true,
                'dining_table_id' => $table->id,
                'client_reference' => 'first-invoice',
                'currency' => 'SAR',
                'subtotal_amount' => 10,
                'tax_amount' => 1.5,
                'total_amount' => 11.5,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_amount' => 1.5,
                    'name' => 'شاي',
                ]],
            ],
        ], register: false);
        $this->assertTrue($orderPush['success']);

        $order = Order::query()->where('client_reference', 'first-invoice')->firstOrFail();
        $this->assertNull($order->table_session_id);
        $this->assertTrue((bool) ($order->metadata['offline_sale'] ?? false));
        $this->assertSame('open', TableSession::withoutGlobalScopes()->firstOrFail()->status);

        $invoicePush = $this->push($token, $workspace, 'POS-LIFE', [
            'id' => 'op-first-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'table',
                'order_local_id' => 'first-invoice',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'LOCAL-1',
                'payment_method' => 'cash',
                'total_amount' => 11.5,
            ],
        ], register: false);
        $this->assertTrue($invoicePush['success']);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertEmpty($invoicePush['failed'] ?? []);
    }

    public function test_qr_menu_order_pull_includes_table_session_and_source(): void
    {
        [$token, $workspace, $item, $table] = $this->bootTable();

        $this->get(route('menu.table', [
            'workspace' => $workspace->slug,
            'token' => $table->qr_token,
        ]))->assertOk();

        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', [
                'workspace' => $workspace->slug,
                'token' => $table->qr_token,
            ]), [
                'guest_session_token' => $guest->token,
                'client_reference' => 'qr-life-order-1',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect();

        $order = Order::query()->where('source', 'qr_menu')->latest('id')->firstOrFail();
        $this->assertSame($table->id, (int) $order->dining_table_id);
        $this->assertNotNull($order->table_session_id);
        $this->assertSame('qr_menu', $order->source);

        $this->registerDevice($token, $workspace, 'POS-CASHIER');
        $pull = $this->pull($token, $workspace, 'POS-CASHIER');

        $orderChange = collect($pull['changes'] ?? [])
            ->where('entity', 'order')
            ->firstWhere(fn ($change) => (int) ($change['id'] ?? 0) === (int) $order->id);
        $this->assertNotNull($orderChange);
        $this->assertSame($table->id, (int) ($orderChange['data']['dining_table_id'] ?? 0));
        $this->assertSame((int) $order->table_session_id, (int) ($orderChange['data']['table_session_id'] ?? 0));
        $this->assertSame('qr_menu', $orderChange['data']['source'] ?? null);

        $tableChange = collect($pull['changes'] ?? [])
            ->where('entity', 'table')
            ->filter(fn ($change) => (int) ($change['id'] ?? 0) === (int) $table->id)
            ->last();
        $this->assertNotNull($tableChange);
        $this->assertSame((int) $order->table_session_id, (int) ($tableChange['data']['session_id'] ?? 0));
        $this->assertTrue((bool) ($tableChange['data']['session_open'] ?? false));
        $this->assertNotEmpty($tableChange['data']['opened_at'] ?? null);

        $this->assertTrue(
            PosSyncChange::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('entity_type', 'order')
                ->where('entity_id', $order->id)
                ->exists()
        );

        $retry = $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', [
                'workspace' => $workspace->slug,
                'token' => $table->qr_token,
            ]), [
                'guest_session_token' => $guest->token,
                'client_reference' => 'qr-life-order-1',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ]);
        $retry->assertRedirect();
        $this->assertSame(1, Order::query()->where('source', 'qr_menu')->count());
    }

    public function test_session_open_and_close_without_table_row_change_reach_pull_log(): void
    {
        [$token, $workspace, $item, $table] = $this->bootTable();
        unset($item);
        $this->registerDevice($token, $workspace, 'POS-WATCH');

        $before = (int) PosSyncChange::withoutGlobalScopes()->max('id');

        // Laravel-side open with no orders: dining_tables.status stays
        // "available", so only the TableSession row changes.
        $service = app(\App\Services\Pos\PosOrderService::class);
        $session = $service->openSession($table->fresh());
        $table->refresh();
        $this->assertSame('available', $table->status);

        $pull = $this->pullSince($token, $workspace, 'POS-WATCH', $before);
        $openChange = collect($pull['changes'])
            ->where('entity', 'table')
            ->filter(fn ($change) => (int) $change['id'] === (int) $table->id)
            ->last();
        $this->assertNotNull($openChange, 'session open must surface as a table change');
        $this->assertSame((int) $session->id, (int) ($openChange['data']['session_id'] ?? 0));
        $this->assertTrue((bool) $openChange['data']['session_open']);
        $this->assertNotEmpty($openChange['data']['opened_at']);

        $cursor = (int) $pull['cursor'];
        $service->closeSession($session->fresh(), (int) $workspace->owner_user_id);

        $pull = $this->pullSince($token, $workspace, 'POS-WATCH', $cursor);
        $closeChange = collect($pull['changes'])
            ->where('entity', 'table')
            ->filter(fn ($change) => (int) $change['id'] === (int) $table->id)
            ->last();
        $this->assertNotNull($closeChange, 'session close must surface as a table change');
        $this->assertNull($closeChange['data']['session_id']);
        $this->assertFalse((bool) $closeChange['data']['session_open']);
        $this->assertSame('available', $closeChange['data']['status']);
    }

    public function test_device_owned_orders_on_one_sitting_are_not_folded_together(): void
    {
        [$token, $workspace, $item, $table] = $this->bootTable();

        $this->push($token, $workspace, 'POS-TWO', [
            'id' => 'op-open-two',
            'type' => 'table_session.open',
            'data' => ['table_server_id' => $table->id, 'session_client_id' => 'sess-two-orders'],
        ]);

        foreach (['ord-a', 'ord-b'] as $ref) {
            $result = $this->push($token, $workspace, 'POS-TWO', [
                'id' => 'op-'.$ref,
                'type' => 'order.created',
                'data' => [
                    'order_type' => 'table',
                    'dining_table_id' => $table->id,
                    'client_reference' => $ref,
                    'items' => [[
                        'pos_menu_item_id' => $item->id,
                        'quantity' => 1,
                        'unit_price' => 10,
                        'name' => 'شاي',
                    ]],
                ],
            ], register: false);
            $this->assertTrue($result['success']);
            $this->assertSame($ref, Order::query()->findOrFail($result['accepted'][0]['entity_id'])->client_reference);
        }

        $orders = Order::query()->whereIn('client_reference', ['ord-a', 'ord-b'])->with('items')->get();
        $this->assertCount(2, $orders);
        $this->assertSame([1, 1], $orders->map(fn ($o) => (int) $o->items->sum('quantity'))->all());
        $this->assertSame(1, $orders->pluck('table_session_id')->unique()->count());

        $pull = $this->pull($token, $workspace, 'POS-TWO');
        $pulledRefs = collect($pull['changes'])
            ->where('entity', 'order')
            ->pluck('data.client_reference')
            ->unique()
            ->values()
            ->all();
        $this->assertEqualsCanonicalizing(['ord-a', 'ord-b'], $pulledRefs);
    }

    public function test_unpaid_table_order_flagged_offline_sale_attaches_to_the_open_sitting(): void
    {
        [$token, $workspace, $item, $table] = $this->bootTable();

        $this->push($token, $workspace, 'POS-LIVE', [
            'id' => 'op-open-live',
            'type' => 'table_session.open',
            'data' => ['table_server_id' => $table->id, 'session_client_id' => 'sess-live'],
        ]);
        $session = TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->firstOrFail();

        // Exactly what the cashier enqueues for a table order (local-first row).
        $result = $this->push($token, $workspace, 'POS-LIVE', [
            'id' => 'op-ord-live',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'dining_table_id' => $table->id,
                'table_local_id' => 'w'.$workspace->id.'_tbl_'.$table->id,
                'session_local_id' => 'sess-live',
                'client_reference' => 'ord-live',
                'pos_status' => 'new',
                'payment_status' => 'unpaid',
                'offline_sale' => true,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 2,
                    'unit_price' => 10,
                    'name' => 'شاي',
                ]],
            ],
        ], register: false);
        $this->assertTrue($result['success']);

        $order = Order::query()->where('client_reference', 'ord-live')->with('items')->firstOrFail();
        $this->assertSame($session->id, (int) $order->table_session_id);
        $this->assertSame($table->id, (int) $order->dining_table_id);
        $this->assertSame(2, (int) $order->items->sum('quantity'));
        $this->assertSame(1, TableSession::query()->where('dining_table_id', $table->id)->count());

        // The Laravel table page lists exactly this order under the sitting.
        $listed = Order::query()
            ->where('dining_table_id', $table->id)
            ->where('table_session_id', $session->id)
            ->whereIn('source', ['pos', 'qr_menu'])
            ->pluck('client_reference')
            ->all();
        $this->assertSame(['ord-live'], $listed);
    }

    public function test_paid_offline_sale_still_does_not_open_a_sitting(): void
    {
        [$token, $workspace, $item, $table] = $this->bootTable();

        $result = $this->push($token, $workspace, 'POS-PAID', [
            'id' => 'op-ord-paid',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'dining_table_id' => $table->id,
                'client_reference' => 'ord-paid',
                'pos_status' => 'completed',
                'payment_status' => 'paid',
                'offline_sale' => true,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'name' => 'شاي',
                ]],
            ],
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame(0, TableSession::query()->where('dining_table_id', $table->id)->count());
        $this->assertNull(Order::query()->where('client_reference', 'ord-paid')->firstOrFail()->table_session_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function pullSince(string $token, Workspace $workspace, string $deviceId, int $cursor): array
    {
        return $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => $deviceId,
            ])
            ->postJson('/api/cashier/v1/sync/pull', [
                'device_id' => $deviceId,
                'cursor' => $cursor,
                'limit' => 200,
            ])
            ->assertOk()
            ->json('data');
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: DiningTable}
     */
    private function bootTable(): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 1',
            'status' => 'available',
            'qr_token' => 'qr-life-1',
        ]);

        return [$token, $workspace, $item, $table];
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

    /**
     * @return array<string, mixed>
     */
    private function pull(string $token, Workspace $workspace, string $deviceId): array
    {
        return $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => $deviceId,
            ])
            ->postJson('/api/cashier/v1/sync/pull', [
                'device_id' => $deviceId,
                'cursor' => 0,
                'limit' => 200,
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
