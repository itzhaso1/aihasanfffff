<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Models\PosSyncOperation;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPhase2AOfflineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_order_without_snapshot_still_uses_catalog_price(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 12);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => 'http-no-snapshot',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated();

        $httpOrder = $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'http-no-snapshot')->with('items')->firstOrFail());
        $this->assertSame(12.0, (float) $httpOrder->items->first()->unit_price);
        $this->assertSame(24.0, (float) $httpOrder->subtotal);
        $this->assertSame(0.0, (float) $httpOrder->items->first()->discount_amount);

        $push = $this->pushOrder($token, $workspace, 'POS-2A', [
            'id' => 'op-sync-no-snapshot',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'sync-no-snapshot',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                ],
            ],
        ]);

        $this->assertTrue($push['success']);
        $syncOrder = $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'sync-no-snapshot')->with('items')->firstOrFail());
        $this->assertSame(12.0, (float) $syncOrder->items->first()->unit_price);
        $this->assertSame(12.0, (float) $syncOrder->subtotal);
        $this->assertSame(1.80, (float) $syncOrder->tax_amount);
        $this->assertSame(13.80, (float) $syncOrder->total_amount);
    }

    public function test_snapshot_unit_price_is_stored_as_sold_price(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 12);

        $this->pushOrder($token, $workspace, 'POS-2A', $this->snapshotOperation(
            id: 'op-snapshot-price',
            reference: 'snap-price',
            itemId: $item->id,
            unitPrice: 10,
        ));

        $order = $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'snap-price')->with('items')->firstOrFail());
        $line = $order->items->first();
        $this->assertSame(10.0, (float) $line->unit_price);
        $this->assertSame(10.0, (float) $line->total_amount);
        $this->assertSame('شاي مجمد', $line->product_name);
    }

    public function test_catalog_price_change_before_request_does_not_reprice_snapshot(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);

        $item->update(['price' => 12]);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => 'http-frozen-price',
                'currency' => 'SAR',
                'items' => [
                    [
                        'pos_menu_item_id' => $item->id,
                        'quantity' => 1,
                        'unit_price' => 10,
                        'name' => 'شاي وقت البيع',
                    ],
                ],
            ])
            ->assertCreated();

        $httpOrder = $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'http-frozen-price')->with('items')->firstOrFail());
        $this->assertSame(10.0, (float) $httpOrder->items->first()->unit_price);
        $this->assertSame(10.0, (float) $httpOrder->subtotal);
        $this->assertSame('SAR', $httpOrder->currency);

        $this->pushOrder($token, $workspace, 'POS-2A', $this->snapshotOperation(
            id: 'op-frozen-after-catalog',
            reference: 'sync-frozen-price',
            itemId: $item->id,
            unitPrice: 10,
        ));

        $syncOrder = $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'sync-frozen-price')->with('items')->firstOrFail());
        $this->assertSame(10.0, (float) $syncOrder->items->first()->unit_price);
        $this->assertNotSame(12.0, (float) $syncOrder->items->first()->unit_price);
        $this->assertSame(12.0, (float) $item->fresh()->price);
    }

    public function test_snapshot_discount_tax_and_total_remain_historical(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 12);

        $this->pushOrder($token, $workspace, 'POS-2A', [
            'id' => 'op-full-snapshot',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'full-snapshot',
                'currency' => 'SAR',
                'subtotal_amount' => 9.00,
                'discount_amount' => 1.00,
                'tax_amount' => 0.90,
                'total_amount' => 8.90,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount_amount' => 1,
                    'tax_amount' => 0.90,
                    'name' => 'شاي مجمد',
                ]],
            ],
        ]);

        $order = $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'full-snapshot')->with('items')->firstOrFail());
        $line = $order->items->first();

        $this->assertSame(10.0, (float) $line->unit_price);
        $this->assertSame(1.0, (float) $line->discount_amount);
        $this->assertSame(9.0, (float) $line->total_amount);
        $this->assertSame(9.0, (float) $order->subtotal);
        $this->assertSame(1.0, (float) $order->discount_amount);
        $this->assertSame(1.20, (float) $order->tax_amount);
        $this->assertSame(9.20, (float) $order->total_amount);
        $this->assertSame('SAR', $order->currency);
        $this->assertSame(0.9, (float) data_get($order->metadata, 'untrusted_client_totals.tax_amount'));

        $this->assertTrue(
            PosSyncChange::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('entity_type', 'order')
                ->where('entity_id', $order->id)
                ->exists()
        );
    }

    public function test_duplicate_operation_acks_same_order_without_second_inventory_deduction(): void
    {
        [$token, $workspace, $item, $product] = $this->bootCashierWithInventory();

        $operation = [
            'id' => 'op-dup-inventory',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'dup-inv-ord',
                'currency' => 'SAR',
                'subtotal_amount' => 10,
                'tax_amount' => 1.50,
                'total_amount' => 11.50,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 2,
                    'unit_price' => 5,
                ]],
            ],
        ];

        $first = $this->pushOrder($token, $workspace, 'POS-2A', $operation);
        $this->assertTrue($first['success']);
        $this->assertSame('applied', $first['accepted'][0]['status']);
        $orderId = (int) $first['accepted'][0]['entity_id'];

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'dup-inv-ord')->count()));
        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->where('type', 'remove')
            ->count());
        $this->assertSame(18, (int) $product->fresh()->stock);

        $second = $this->pushOrder($token, $workspace, 'POS-2A', $operation);
        $this->assertTrue($second['success']);
        $this->assertSame('duplicate', $second['accepted'][0]['status']);
        $this->assertSame($orderId, (int) $second['accepted'][0]['entity_id']);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'dup-inv-ord')->count()));
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-dup-inventory')->count());
        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->where('type', 'remove')
            ->count());
        $this->assertSame(18, (int) $product->fresh()->stock);
    }

    public function test_two_client_references_create_independent_orders(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 10);
        $this->registerDevice($token, $workspace, 'POS-A');
        $this->registerDevice($token, $workspace, 'POS-B');

        foreach (['POS-A' => 'device-a-sale', 'POS-B' => 'device-b-sale'] as $device => $ref) {
            $result = $this->pushOrder($token, $workspace, $device, [
                'id' => $ref.'-op',
                'type' => 'order.created',
                'data' => [
                    'order_type' => 'takeaway',
                    'client_reference' => $ref,
                    'items' => [[
                        'pos_menu_item_id' => $item->id,
                        'quantity' => 1,
                        'unit_price' => 10,
                    ]],
                ],
            ], register: false);

            $this->assertTrue($result['success']);
        }

        $orders = $this->inWorkspace($workspace, fn () => Order::query()->whereIn('client_reference', ['device-a-sale', 'device-b-sale'])->get());
        $this->assertCount(2, $orders);
        $this->assertNotSame($orders[0]->id, $orders[1]->id);
        $this->assertSame(2, PosSyncOperation::withoutGlobalScopes()->count());
    }

    public function test_inventory_is_deducted_exactly_once_for_a_snapshot_sale(): void
    {
        [$token, $workspace, $item, $product] = $this->bootCashierWithInventory();

        $this->pushOrder($token, $workspace, 'POS-2A', [
            'id' => 'op-once-stock',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'once-stock',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 3,
                    'unit_price' => 4,
                ]],
            ],
        ]);

        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->count());
        $this->assertSame(17, (int) $product->fresh()->stock);
        $movement = InventoryMovement::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->first();
        $this->assertSame('remove', $movement->type);
        $this->assertSame(3, (int) $movement->quantity);
        $this->assertSame(Order::class, $movement->reference_type);
    }

    public function test_invalid_snapshots_are_rejected_without_catalog_fallback(): void
    {
        [$token, $workspace, $item] = $this->bootCashier(catalogPrice: 12);
        $this->registerDevice($token, $workspace, 'POS-2A');

        $cases = [
            'negative_price' => [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => -1]],
            ],
            'negative_quantity' => [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => -2, 'unit_price' => 10]],
            ],
            'zero_quantity' => [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 0, 'unit_price' => 10]],
            ],
            'malformed_price' => [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 'ten']],
            ],
            'negative_tax' => [
                'tax_amount' => -1,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
            'malformed_tax' => [
                'tax_amount' => 'abc',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
            'negative_discount' => [
                'discount_amount' => -3,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
            'invalid_discount_percent' => [
                'discount_percent' => 140,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
            'line_discount_exceeds_gross' => [
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount_amount' => 20,
                ]],
            ],
            'invalid_currency_short' => [
                'currency' => 'US',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
            'invalid_currency_numeric' => [
                'currency' => '12$',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
            'inconsistent_total' => [
                'subtotal_amount' => 10,
                'discount_amount' => 0,
                'tax_amount' => 1,
                'total_amount' => 99,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]],
            ],
        ];

        foreach ($cases as $name => $data) {
            $result = $this->pushOrder($token, $workspace, 'POS-2A', [
                'id' => 'op-invalid-'.$name,
                'type' => 'order.created',
                'data' => array_merge([
                    'order_type' => 'takeaway',
                    'client_reference' => 'invalid-'.$name,
                ], $data),
            ], register: false);

            $this->assertFalse($result['success'], $name.' should fail');
            $this->assertNotEmpty($result['failed'], $name.' should report a failed operation');
            $this->assertFalse($result['failed'][0]['retryable'], $name.' must not be retried as a catalog fallback');
            $this->assertSame(0, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'invalid-'.$name)->count()), $name.' must not store an order');
        }

        $http = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => 'http-negative-price',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => -4],
                ],
            ]);

        $http->assertStatus(422);
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'http-negative-price')->count()));

        $httpCurrency = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => 'http-bad-currency',
                'currency' => 'US',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10],
                ],
            ]);

        $httpCurrency->assertStatus(422);
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', 'http-bad-currency')->count()));
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem}
     */
    private function bootCashier(float $catalogPrice): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->setTaxRate($workspace, 15);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => $catalogPrice,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);

        return [$token, $workspace, $item];
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: Product}
     */
    private function bootCashierWithInventory(): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->setTaxRate($workspace, 15);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سكر',
            'slug' => 'sugar-2a',
            'sku' => 'SUGAR-2A',
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
        $token = $this->loginToken($owner);

        return [$token, $workspace, $item, $product];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function pushOrder(
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
    private function snapshotOperation(string $id, string $reference, int $itemId, float $unitPrice): array
    {
        return [
            'id' => $id,
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => $reference,
                'currency' => 'SAR',
                'items' => [[
                    'pos_menu_item_id' => $itemId,
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'name' => 'شاي مجمد',
                ]],
            ],
        ];
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
