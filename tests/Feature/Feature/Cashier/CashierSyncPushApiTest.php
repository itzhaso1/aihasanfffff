<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierSyncPushApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_push_returns_401(): void
    {
        $this->postJson('/api/cashier/v1/sync/push', [
            'device_id' => 'POS-001',
            'operations' => [
                [
                    'id' => 'op-unauth-1',
                    'type' => 'order.created',
                    'data' => ['order_type' => 'takeaway', 'items' => []],
                ],
            ],
        ])->assertStatus(401);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, PosSyncOperation::withoutGlobalScopes()->count());
    }

    public function test_unregistered_device_cannot_push(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-UNREG',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-UNREG',
                'operations' => [
                    [
                        'id' => 'op-unreg-1',
                        'type' => 'order.created',
                        'data' => ['order_type' => 'takeaway', 'items' => []],
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_device_header_mismatch_is_rejected(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-001');

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-002',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-001',
                'operations' => [],
            ])
            ->assertStatus(422);
    }

    public function test_batch_push_is_idempotent_and_returns_ack(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-001');

        $operation = [
            'id' => '11111111-1111-4111-8111-111111111111',
            'type' => 'order.created',
            'created_at' => '2026-09-02T05:20:00Z',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'ord-pos-001',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 2],
                ],
            ],
        ];

        $first = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-001',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-001',
                'operations' => [$operation],
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($first['success']);
        $this->assertCount(1, $first['accepted']);
        $this->assertSame([], $first['failed']);
        $this->assertSame('applied', $first['accepted'][0]['status']);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'ord-pos-001')->count()));
        $orderId = (int) $first['accepted'][0]['entity_id'];
        $this->assertGreaterThan(0, $orderId);
        $this->assertGreaterThan(0, (int) $first['server_cursor']);

        $second = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-001',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-001',
                'operations' => [$operation],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('duplicate', $second['accepted'][0]['status']);
        $this->assertSame($orderId, (int) $second['accepted'][0]['entity_id']);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'ord-pos-001')->count()));
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->count());
    }

    public function test_pos_alias_push_and_pull_work(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'قهوة',
            'price' => 8,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-002');

        $baseline = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-002',
            ])
            ->postJson('/api/pos/sync/pull', [
                'device_id' => 'POS-002',
                'cursor' => 0,
                'limit' => 0,
            ])
            ->assertOk()
            ->json('data');
        $cursor = (int) $baseline['server_cursor'];

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-002',
            ])
            ->postJson('/api/pos/sync/push', [
                'device_id' => 'POS-002',
                'operations' => [[
                    'id' => 'op-alias-order-1',
                    'type' => 'order.created',
                    'data' => [
                        'order_type' => 'takeaway',
                        'client_reference' => 'alias-ord-1',
                        'items' => [
                            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                        ],
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $pull = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-002',
            ])
            ->postJson('/api/pos/sync/pull', [
                'device_id' => 'POS-002',
                'cursor' => $cursor,
            ])
            ->assertOk()
            ->json('data');

        $entities = array_column($pull['changes'], 'entity');
        $this->assertContains('order', $entities);
        $this->assertSame('POS-002', $pull['device_id']);
    }

    public function test_mixed_batch_keeps_valid_ops_and_acks_failures(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عصير',
            'price' => 6,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-003');

        $result = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-003',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-003',
                'operations' => [
                    [
                        'id' => 'op-good-1',
                        'type' => 'order.created',
                        'data' => [
                            'order_type' => 'takeaway',
                            'client_reference' => 'mixed-good',
                            'items' => [
                                ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                            ],
                        ],
                    ],
                    [
                        'id' => 'op-bad-1',
                        'type' => 'unknown.op',
                        'data' => [],
                    ],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['accepted']);
        $this->assertCount(1, $result['failed']);
        $this->assertFalse($result['failed'][0]['retryable']);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'mixed-good')->count()));
    }

    public function test_stock_movement_is_ledger_based_and_sale_is_not_double_applied(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سكر',
            'slug' => 'sugar-pos',
            'sku' => 'SUGAR-1',
            'price' => 4,
            'currency' => 'SAR',
            'stock' => 20,
            'status' => 'active',
        ]);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'name' => 'سكر',
            'price' => 4,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-STOCK');

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-STOCK',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-STOCK',
                'operations' => [
                    [
                        'id' => 'op-sale-order',
                        'type' => 'order.created',
                        'data' => [
                            'order_type' => 'takeaway',
                            'client_reference' => 'stock-sale-ord',
                            'items' => [
                                ['pos_menu_item_id' => $item->id, 'quantity' => 3],
                            ],
                        ],
                    ],
                    [
                        'id' => 'op-sale-movement',
                        'type' => 'stock.movement',
                        'data' => [
                            'product_id' => $product->id,
                            'kind' => 'sale',
                            'quantity' => 3,
                        ],
                    ],
                    [
                        'id' => 'op-purchase-movement',
                        'type' => 'stock.movement',
                        'data' => [
                            'product_id' => $product->id,
                            'kind' => 'purchase',
                            'quantity' => 5,
                            'notes' => 'توريد',
                        ],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $product->refresh();
        // order.created deducted 3; sale movement skipped; purchase added 5 → 22
        $this->assertSame(22, (int) $product->stock);
        $this->assertSame(2, InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->count());
    }

    public function test_two_devices_do_not_share_operation_uuids_across_replays(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'ماء',
            'price' => 2,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-A');
        $this->registerDevice($token, $workspace, 'POS-B');

        foreach (['POS-A' => 'dev-a-ord', 'POS-B' => 'dev-b-ord'] as $device => $ref) {
            $this->withToken($token)
                ->withHeaders([
                    'X-Workspace-Id' => (string) $workspace->id,
                    'X-Device-Id' => $device,
                ])
                ->postJson('/api/cashier/v1/sync/push', [
                    'device_id' => $device,
                    'operations' => [[
                        'id' => $ref.'-op',
                        'type' => 'order.created',
                        'data' => [
                            'order_type' => 'takeaway',
                            'client_reference' => $ref,
                            'items' => [
                                ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                            ],
                        ],
                    ]],
                ])
                ->assertOk()
                ->assertJsonPath('data.success', true);
        }

        $this->assertSame(2, $this->inWorkspace($workspace, fn () => Order::query()->whereIn('client_reference', ['dev-a-ord', 'dev-b-ord'])->count()));
        $this->assertSame(2, PosSyncOperation::withoutGlobalScopes()->count());
    }

    public function test_two_devices_opening_same_table_record_conflict_without_merge(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 5',
            'status' => 'available',
            'qr_token' => 'qr-conflict-5',
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-A');
        $this->registerDevice($token, $workspace, 'POS-B');

        $openA = [
            'id' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
            'type' => 'table_session.open',
            'data' => [
                'table_server_id' => $table->id,
                'session_client_id' => 'session-a',
            ],
        ];
        $openB = [
            'id' => 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb',
            'type' => 'table_session.open',
            'data' => [
                'table_server_id' => $table->id,
                'session_client_id' => 'session-b',
            ],
        ];

        $first = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-A',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-A',
                'operations' => [$openA],
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($first['success']);
        $this->assertFalse($first['accepted'][0]['result']['conflict'] ?? false);

        $second = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-B',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-B',
                'operations' => [$openB],
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($second['success']);
        $result = $second['accepted'][0]['result'];
        $this->assertTrue($result['conflict']);
        $this->assertSame('conflict', $result['conflict_status']);
        $this->assertSame('session-b', $result['local_session_id']);
        $this->assertSame('POS-B', $result['device_id']);
        $this->assertSame($table->id, $result['table_id']);
        $this->assertNotSame($result['accepted_session_id'], $result['server_session_id']);

        $this->assertSame(
            2,
            $this->inWorkspace($workspace, fn () => TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->count())
        );
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Order::query()->count()));
    }

    public function test_duplicate_table_session_open_does_not_create_second_session(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 7',
            'status' => 'available',
            'qr_token' => 'qr-dup-7',
        ]);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, 'POS-DUP');

        $operation = [
            'id' => 'cccccccc-1111-4111-8111-cccccccccccc',
            'type' => 'table_session.open',
            'data' => [
                'table_server_id' => $table->id,
                'session_client_id' => 'session-dup',
            ],
        ];

        foreach (range(1, 2) as $ignored) {
            $this->withToken($token)
                ->withHeaders([
                    'X-Workspace-Id' => (string) $workspace->id,
                    'X-Device-Id' => 'POS-DUP',
                ])
                ->postJson('/api/cashier/v1/sync/push', [
                    'device_id' => 'POS-DUP',
                    'operations' => [$operation],
                ])
                ->assertOk()
                ->assertJsonPath('data.success', true);
        }

        $this->assertSame(
            1,
            $this->inWorkspace($workspace, fn () => TableSession::query()->where('dining_table_id', $table->id)->count())
        );
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->count());
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
