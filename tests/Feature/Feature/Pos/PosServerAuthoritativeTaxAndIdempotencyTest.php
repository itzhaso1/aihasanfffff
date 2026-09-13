<?php

namespace Tests\Feature\Feature\Pos;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosServerAuthoritativeTaxAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_zero_tax_is_replaced_by_server_tax(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 15, price: 100);

        $this->push($token, $workspace, [
            'id' => 'op-tax-zero',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'tax-zero',
                'tax_amount' => 0,
                'total_amount' => 100,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'tax_amount' => 0,
                    'total_amount' => 100,
                ]],
            ],
        ]);

        $order = Order::withoutGlobalScopes()->where('client_reference', 'tax-zero')->firstOrFail();
        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(15.0, (float) $order->tax_amount);
        $this->assertSame(115.0, (float) $order->total_amount);
        $lineTax = OrderItem::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->first();
        $this->assertSame(15.0, (float) $lineTax?->tax_amount);
    }

    public function test_client_huge_tax_is_ignored(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 15, price: 40);

        $this->push($token, $workspace, [
            'id' => 'op-tax-huge',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'tax-huge',
                'tax_amount' => 9999,
                'total_amount' => 10039,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'tax_amount' => 9999,
                ]],
            ],
        ]);

        $order = Order::withoutGlobalScopes()->where('client_reference', 'tax-huge')->firstOrFail();
        $this->assertSame(6.0, (float) $order->tax_amount);
        $this->assertSame(46.0, (float) $order->total_amount);
    }

    public function test_client_grand_total_does_not_win(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 10, price: 50);

        $this->push($token, $workspace, [
            'id' => 'op-total-lie',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => 'total-lie',
                'subtotal' => 1,
                'total_amount' => 1,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
            ],
        ]);

        $order = Order::withoutGlobalScopes()->where('client_reference', 'total-lie')->firstOrFail();
        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(10.0, (float) $order->tax_amount);
        $this->assertSame(110.0, (float) $order->total_amount);
    }

    public function test_same_uuid_same_payload_is_idempotent(): void
    {
        [$token, $workspace, $item, $product] = $this->bootWithStock();
        $operation = $this->saleOperation('op-idem', 'idem-sale', $item->id);

        $first = $this->push($token, $workspace, $operation);
        $second = $this->push($token, $workspace, $operation, register: false);

        $this->assertSame('applied', $first['accepted'][0]['status']);
        $this->assertSame('duplicate', $second['accepted'][0]['status']);
        $this->assertSame($first['accepted'][0]['entity_id'], $second['accepted'][0]['entity_id']);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'idem-sale')->count());
        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()->where('product_id', $product->id)->where('type', 'remove')->count());
        $this->assertSame(8, (int) $product->fresh()->stock);
    }

    public function test_same_uuid_different_payload_is_rejected(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 15, price: 10);
        $this->push($token, $workspace, $this->saleOperation('op-conflict', 'conflict-sale', $item->id, 1));

        $retry = $this->push($token, $workspace, $this->saleOperation('op-conflict', 'conflict-sale', $item->id, 9), register: false, expectSuccess: false);

        $this->assertFalse($retry['success']);
        $this->assertSame(409, $this->conflictStatus($retry));
        $conflict = Order::withoutGlobalScopes()
            ->with(['items' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('client_reference', 'conflict-sale')
            ->firstOrFail();
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'conflict-sale')->count());
        $this->assertSame(1, (int) $conflict->items->sum('quantity'));
    }

    public function test_new_uuid_creates_a_new_sale(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 15, price: 10);
        $this->push($token, $workspace, $this->saleOperation('op-new-a', 'sale-a', $item->id));
        $this->push($token, $workspace, $this->saleOperation('op-new-b', 'sale-b', $item->id), register: false);

        $this->assertSame(2, Order::withoutGlobalScopes()->whereIn('client_reference', ['sale-a', 'sale-b'])->count());
    }

    public function test_timeout_retry_and_restart_reuse_do_not_duplicate_side_effects(): void
    {
        [$token, $workspace, $item, $product] = $this->bootWithStock();
        $operation = $this->saleOperation('op-restart', 'restart-sale', $item->id, 2);

        $this->push($token, $workspace, $operation);
        $this->push($token, $workspace, $operation, register: false);
        $this->push($token, $workspace, $operation, register: false);

        $order = Order::withoutGlobalScopes()->where('client_reference', 'restart-sale')->firstOrFail();
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'restart-sale')->count());
        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()->where('product_id', $product->id)->where('type', 'remove')->count());
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame('pending', Order::withoutGlobalScopes()->find($order->id)?->payment_status);

        $this->push($token, $workspace, [
            'id' => 'op-restart-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_local_id' => 'restart-sale',
                'order_server_id' => $order->id,
                'payment_method' => 'cash',
            ],
        ], register: false);
        $this->push($token, $workspace, [
            'id' => 'op-restart-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_local_id' => 'restart-sale',
                'order_server_id' => $order->id,
                'payment_method' => 'cash',
            ],
        ], register: false);

        $this->assertSame(1, PosCashierInvoice::withoutGlobalScopes()->count());
        $this->assertSame('paid', Order::withoutGlobalScopes()->find($order->id)?->payment_status);
        $this->assertSame(1, InventoryMovement::withoutGlobalScopes()->where('product_id', $product->id)->where('type', 'remove')->count());
    }

    public function test_http_order_endpoint_also_ignores_client_tax(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 15, price: 20);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => 'http-tax-tamper',
                'tax_amount' => 0,
                'total_amount' => 20,
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 1,
                    'tax_amount' => 0,
                    'line_total' => 1,
                ]],
            ])
            ->assertCreated();

        $order = Order::withoutGlobalScopes()->where('client_reference', 'http-tax-tamper')->firstOrFail();
        $this->assertSame(3.0, (float) $order->tax_amount);
        $this->assertSame(23.0, (float) $order->total_amount);
    }

    public function test_concurrent_duplicate_uuid_is_serialized_to_one_sale(): void
    {
        [$token, $workspace, $item] = $this->boot(taxRate: 15, price: 10);
        $this->registerDevice($token, $workspace, 'POS-P02');
        $operation = $this->saleOperation('op-parallel', 'parallel-sale', $item->id);

        $first = $this->push($token, $workspace, $operation, register: false);
        $locked = PosSyncOperation::withoutGlobalScopes()
            ->where('operation_uuid', 'op-parallel')
            ->firstOrFail();
        $this->assertSame(PosSyncOperation::STATUS_ACCEPTED, $locked->status);

        $again = $this->push($token, $workspace, $operation, register: false);
        $this->assertSame($first['accepted'][0]['entity_id'], $again['accepted'][0]['entity_id']);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'parallel-sale')->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-parallel')->count());
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem}
     */
    private function boot(float $taxRate, float $price): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->setTaxRate($workspace, $taxRate);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$this->loginToken($owner), $workspace, $item];
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem, 3: Product}
     */
    private function bootWithStock(): array
    {
        [$token, $workspace, $item] = $this->boot(15, 12);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سكر',
            'slug' => 'sugar-p02',
            'sku' => 'SUGAR-P02',
            'price' => 4,
            'currency' => 'SAR',
            'stock' => 10,
            'status' => 'active',
        ]);
        $item->update(['product_id' => $product->id]);

        return [$token, $workspace, $item->fresh(), $product];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleOperation(string $id, string $reference, int $itemId, int $quantity = 2): array
    {
        return [
            'id' => $id,
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => $reference,
                'items' => [[
                    'pos_menu_item_id' => $itemId,
                    'quantity' => $quantity,
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
        array $operation,
        bool $register = true,
        bool $expectSuccess = true,
    ): array {
        if ($register) {
            $this->registerDevice($token, $workspace, 'POS-P02');
        }

        $response = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-P02',
            ])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-P02',
                'operations' => [$operation],
            ])
            ->assertOk();

        $payload = $response->json('data');
        if ($expectSuccess) {
            $this->assertTrue($payload['success']);
        }

        return $payload;
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
        return (string) $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم test',
            'device_type' => 'cashier',
        ])->assertOk()->json('data.token');
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

    private function setTaxRate(Workspace $workspace, float $rate): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $pos = is_array($settings['pos'] ?? null) ? $settings['pos'] : [];
        $pos['tax_rate'] = $rate;
        $settings['pos'] = $pos;
        $workspace->update(['settings' => $settings]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function conflictStatus(array $payload): int
    {
        $error = (string) ($payload['failed'][0]['error'] ?? '');
        $this->assertStringContainsString('بيانات مختلفة', $error);

        return 409;
    }
}
