<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\Subscription;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierMenuTableMasterSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_product_and_table_master_crud_round_trip(): void
    {
        [$token, $workspace] = $this->bootCashierWorkspace();

        $cat = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-cat-create',
            'type' => 'category.created',
            'data' => ['name' => 'مشروبات', 'is_active' => true, 'sort_order' => 1],
        ]);
        $this->assertTrue($cat['success']);
        $categoryId = (int) $cat['accepted'][0]['result']['id'];
        $this->assertSame('مشروبات', PosItemCategory::query()->findOrFail($categoryId)->name);

        $prod = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-prod-create',
            'type' => 'product.created',
            'data' => [
                'name' => 'شاي',
                'price' => 10,
                'currency' => 'SAR',
                'pos_item_category_id' => $categoryId,
                'is_active' => true,
            ],
        ], register: false);
        $this->assertTrue($prod['success']);
        $productId = (int) $prod['accepted'][0]['result']['id'];
        $item = PosMenuItem::query()->findOrFail($productId);
        $this->assertSame(10.0, (float) $item->price);
        $this->assertSame($categoryId, $item->pos_item_category_id);
        $this->assertSame('SAR', $item->currency);

        $renameCat = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-cat-update',
            'type' => 'category.updated',
            'data' => ['server_id' => $categoryId, 'name' => 'مشروبات ساخنة'],
        ], register: false);
        $this->assertTrue($renameCat['success']);
        $this->assertSame('مشروبات ساخنة', PosItemCategory::query()->findOrFail($categoryId)->name);

        $price = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-prod-update',
            'type' => 'product.updated',
            'data' => ['server_id' => $productId, 'name' => 'شاي أحمر', 'price' => 12],
        ], register: false);
        $this->assertTrue($price['success']);
        $this->assertSame(12.0, (float) PosMenuItem::query()->findOrFail($productId)->price);

        $table = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-table-create',
            'type' => 'table.created',
            'data' => ['name' => 'طاولة 1'],
        ], register: false);
        $this->assertTrue($table['success']);
        $tableId = (int) $table['accepted'][0]['result']['id'];
        $this->assertSame('طاولة 1', DiningTable::query()->findOrFail($tableId)->name);

        $renameTable = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-table-update',
            'type' => 'table.updated',
            'data' => ['server_id' => $tableId, 'name' => 'VIP 1'],
        ], register: false);
        $this->assertTrue($renameTable['success']);
        $this->assertSame('VIP 1', DiningTable::query()->findOrFail($tableId)->name);
    }

    public function test_retry_uses_operation_uuid_and_does_not_duplicate_catalog_rows(): void
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $op = [
            'id' => 'op-cat-once',
            'type' => 'category.created',
            'data' => ['name' => 'حلويات'],
        ];
        $first = $this->push($token, $workspace, 'POS-MENU', $op);
        $retry = $this->push($token, $workspace, 'POS-MENU', $op, register: false);

        $this->assertSame('applied', $first['accepted'][0]['status']);
        $this->assertSame('duplicate', $retry['accepted'][0]['status']);
        $this->assertSame($first['accepted'][0]['result']['id'], $retry['accepted'][0]['result']['id']);
        $this->assertSame(1, PosItemCategory::query()->where('name', 'حلويات')->count());
        $this->assertSame(1, PosSyncOperation::query()->where('operation_uuid', 'op-cat-once')->count());
    }

    public function test_product_price_change_does_not_reprice_historical_invoice(): void
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة تاريخ',
            'status' => 'available',
            'qr_token' => 'qr-hist-1',
        ]);

        $orderPush = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-hist-order',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'offline_sale' => true,
                'client_reference' => 'table-hist-1',
                'dining_table_id' => $table->id,
                'currency' => 'SAR',
                'subtotal_amount' => 10,
                'tax_amount' => 1.50,
                'total_amount' => 11.50,
                'placed_at' => '2026-09-04T18:15:00+00:00',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_amount' => 1.50,
                    'name' => 'شاي',
                ]],
            ],
        ]);
        $this->assertTrue($orderPush['success']);
        $order = Order::query()->where('client_reference', 'table-hist-1')->firstOrFail();

        $invoicePush = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-hist-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'table',
                'order_local_id' => 'table-hist-1',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000001',
                'closed_at' => '2026-09-04T18:15:00+00:00',
                'payment_method' => 'cash',
                'total_amount' => 11.50,
            ],
        ], register: false);
        $this->assertTrue($invoicePush['success']);

        $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-hist-price',
            'type' => 'product.updated',
            'data' => ['server_id' => $item->id, 'name' => 'شاي', 'price' => 12],
        ], register: false);

        $order->refresh();
        $invoice = PosCashierInvoice::query()->firstOrFail();
        $this->assertSame(10.0, (float) $order->items()->firstOrFail()->unit_price);
        $this->assertSame(11.50, (float) $order->total_amount);
        $this->assertSame(11.50, (float) $invoice->total_amount);
        $this->assertSame(12.0, (float) $item->fresh()->price);
    }

    public function test_table_soft_delete_keeps_historical_invoices_and_rejects_open_sessions(): void
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة للحذف',
            'status' => 'available',
            'qr_token' => 'qr-del-1',
        ]);

        $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-del-order',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'table',
                'offline_sale' => true,
                'client_reference' => 'table-del-1',
                'dining_table_id' => $table->id,
                'currency' => 'SAR',
                'total_amount' => 11.50,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'name' => 'شاي',
                ]],
            ],
        ]);
        $order = Order::query()->where('client_reference', 'table-del-1')->firstOrFail();
        $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-del-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_local_id' => 'table-del-1',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-000009',
                'payment_method' => 'cash',
                'total_amount' => 11.50,
            ],
        ], register: false);

        $deleted = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-table-delete',
            'type' => 'table.deleted',
            'data' => ['server_id' => $table->id],
        ], register: false);
        $this->assertTrue($deleted['success']);
        $this->assertNotNull($table->fresh()?->deleted_at);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(1, Order::query()->where('client_reference', 'table-del-1')->count());

        $live = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة مفتوحة',
            'status' => 'occupied',
            'qr_token' => 'qr-open-1',
        ]);
        TableSession::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'dining_table_id' => $live->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $blocked = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-table-delete-open',
            'type' => 'table.deleted',
            'data' => ['server_id' => $live->id],
        ], register: false);
        $this->assertFalse($blocked['success']);
        $this->assertFalse($blocked['failed'][0]['retryable']);
        $this->assertNotNull($live->fresh());
        $this->assertNull($live->fresh()->deleted_at);
    }

    public function test_category_delete_fails_while_products_remain(): void
    {
        [$token, $workspace] = $this->bootCashierWorkspace();
        $category = PosItemCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'مشروبات',
            'is_active' => true,
        ]);
        PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'pos_item_category_id' => $category->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $result = $this->push($token, $workspace, 'POS-MENU', [
            'id' => 'op-cat-delete-blocked',
            'type' => 'category.deleted',
            'data' => ['server_id' => $category->id],
        ]);
        $this->assertFalse($result['success']);
        $this->assertFalse($result['failed'][0]['retryable']);
        $this->assertNotNull($category->fresh());
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
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();

        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
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
