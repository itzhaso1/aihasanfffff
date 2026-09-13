<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierKitchenOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_cashier_orders_reach_kitchen_pull_without_invoices(): void
    {
        [$token, $workspace, $item, $table] = $this->bootOneTable();

        $tablePush = $this->push($token, $workspace, 'POS-CASHIER', [
            'id' => 'op-kitchen-table',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'offline_sale' => true,
                'client_reference' => 'table-pizza-1',
                'dining_table_id' => $table->id,
                'table_name' => 'طاولة 5',
                'session_local_id' => 'sitting-1',
                'pos_status' => 'new',
                'payment_status' => 'unpaid',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'name' => 'بيتزا',
                    'notes' => 'بدون بصل',
                ]],
            ],
        ]);
        $this->assertTrue($tablePush['success']);

        $takeawayPush = $this->push($token, $workspace, 'POS-CASHIER', [
            'id' => 'op-kitchen-takeaway',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'offline_sale' => true,
                'client_reference' => 'tw-burger-2',
                'pos_status' => 'new',
                'payment_status' => 'unpaid',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 2,
                    'name' => 'برجر',
                ]],
            ],
        ], register: false);
        $this->assertTrue($takeawayPush['success']);

        $deliveryPush = $this->push($token, $workspace, 'POS-CASHIER', [
            'id' => 'op-kitchen-delivery',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'delivery',
                'offline_sale' => true,
                'client_reference' => 'del-1',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'name' => 'برجر',
                ]],
            ],
        ], register: false);
        $this->assertTrue($deliveryPush['success']);

        $this->assertSame(3, Order::query()->count());
        $this->assertSame(0, PosCashierInvoice::query()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, TableSession::withoutGlobalScopes()->count());

        $tableOrder = Order::query()->where('client_reference', 'table-pizza-1')->firstOrFail();
        $this->assertSame('pending', $tableOrder->payment_status);
        $this->assertSame('new', $tableOrder->pos_status);
        $this->assertSame('sitting-1', $tableOrder->metadata['cashier_session_local_id'] ?? null);
        $this->assertNull($tableOrder->table_session_id);
        $this->assertTrue((bool) ($tableOrder->metadata['offline_sale'] ?? false));

        $retry = $this->push($token, $workspace, 'POS-CASHIER', [
            'id' => 'op-kitchen-table',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'offline_sale' => true,
                'client_reference' => 'table-pizza-1',
                'dining_table_id' => $table->id,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                ]],
            ],
        ], register: false);
        $this->assertSame('duplicate', $retry['accepted'][0]['status']);
        $this->assertSame(3, Order::query()->count());

        $this->registerDevice($token, $workspace, 'POS-KITCHEN');
        $pull = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-KITCHEN',
            ])
            ->postJson('/api/cashier/v1/sync/pull', [
                'device_id' => 'POS-KITCHEN',
                'cursor' => 0,
                'limit' => 200,
            ])
            ->assertOk()
            ->json('data');

        $orderChanges = collect($pull['changes'] ?? [])
            ->where('entity', 'order')
            ->values();
        $this->assertNotEmpty($orderChanges);
        $this->assertSame([], collect($pull['changes'] ?? [])->where('entity', 'invoice')->all());
        $this->assertSame([], collect($pull['changes'] ?? [])->where('entity', 'payment')->all());

        $tableSnapshot = collect($orderChanges)->last(
            fn ($change) => ($change['data']['client_reference'] ?? null) === 'table-pizza-1'
        );
        $this->assertNotNull($tableSnapshot);
        $this->assertSame('طاولة 5', $tableSnapshot['data']['table_name'] ?? $table->name);
        $this->assertSame('sitting-1', $tableSnapshot['data']['session_local_id'] ?? null);
        $this->assertSame('بدون بصل', $tableSnapshot['data']['items'][0]['notes'] ?? null);

        $invoicePush = $this->push($token, $workspace, 'POS-CASHIER', [
            'id' => 'op-kitchen-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'tw-burger-2',
                'order_server_id' => Order::query()->where('client_reference', 'tw-burger-2')->value('id'),
                'local_invoice_number' => 'INV-KITCHEN-1',
                'currency' => 'SAR',
                'total_amount' => 20,
                'payment_method' => 'cash',
            ],
        ], register: false);
        $this->assertTrue($invoicePush['success']);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(3, Order::query()->count());

        $takeaway = Order::query()->where('client_reference', 'tw-burger-2')->firstOrFail();
        $this->assertSame('paid', $takeaway->payment_status);
        $this->assertSame('completed', $takeaway->pos_status);
        $this->assertSame('new', Order::query()->where('client_reference', 'table-pizza-1')->value('pos_status'));
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: DiningTable}
     */
    private function bootOneTable(): array
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'برجر',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 5',
            'status' => 'available',
            'qr_token' => 'qr-kitchen-5',
        ]);

        return [$token, $workspace, $item, $table];
    }

    /**
     * @return array{0: string, 1: Workspace}
     */
    private function bootCashierWorkspace(): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        return [$token, $workspace];
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
