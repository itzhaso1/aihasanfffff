<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierKitchenStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_kitchen_status_updates_change_log_without_invoices(): void
    {
        [$token, $workspace, $item] = $this->bootCashier();

        $created = $this->push($token, $workspace, 'POS-PHONE', [
            'id' => 'op-status-create',
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'offline_sale' => true,
                'client_reference' => 'status-1',
                'items' => [[
                    'pos_menu_item_id' => $item->id,
                    'quantity' => 2,
                    'name' => 'برجر',
                ]],
            ],
        ]);
        $this->assertTrue($created['success']);
        $order = Order::query()->where('client_reference', 'status-1')->firstOrFail();
        $this->assertSame('new', $order->pos_status);
        $this->assertSame(0, PosCashierInvoice::query()->count());

        $ready = $this->push($token, $workspace, 'POS-PHONE', [
            'id' => 'op-status-ready',
            'type' => 'order.updated',
            'data' => [
                'kitchen_status' => true,
                'pos_status' => 'ready',
                'client_reference' => 'status-1',
                'order_server_id' => $order->id,
            ],
        ], register: false);
        $this->assertTrue($ready['success']);
        $order->refresh();
        $this->assertSame('ready', $order->pos_status);
        $this->assertSame(0, PosCashierInvoice::query()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());

        $retry = $this->push($token, $workspace, 'POS-PHONE', [
            'id' => 'op-status-ready',
            'type' => 'order.updated',
            'data' => [
                'kitchen_status' => true,
                'pos_status' => 'ready',
                'client_reference' => 'status-1',
                'order_server_id' => $order->id,
            ],
        ], register: false);
        $this->assertSame('duplicate', $retry['accepted'][0]['status']);
        $this->assertSame(1, Order::query()->count());

        $this->registerDevice($token, $workspace, 'POS-WINDOWS');
        $pull = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'POS-WINDOWS',
            ])
            ->postJson('/api/cashier/v1/sync/pull', [
                'device_id' => 'POS-WINDOWS',
                'cursor' => 0,
                'limit' => 200,
            ])
            ->assertOk()
            ->json('data');

        $last = collect($pull['changes'] ?? [])
            ->where('entity', 'order')
            ->last();
        $this->assertSame('ready', $last['data']['pos_status'] ?? null);
        $this->assertSame([], collect($pull['changes'] ?? [])->where('entity', 'invoice')->all());

        $delivered = $this->push($token, $workspace, 'POS-WINDOWS', [
            'id' => 'op-status-delivered',
            'type' => 'order.updated',
            'data' => [
                'kitchen_status' => true,
                'pos_status' => 'delivered',
                'client_reference' => 'status-1',
                'order_server_id' => $order->id,
            ],
        ], register: false);
        $this->assertTrue($delivered['success']);
        $order->refresh();
        $this->assertSame('delivered', $order->pos_status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(0, PosCashierInvoice::query()->count());

        $invoice = $this->push($token, $workspace, 'POS-PHONE', [
            'id' => 'op-status-invoice',
            'type' => 'invoice.created',
            'data' => [
                'order_type' => 'takeaway',
                'order_local_id' => 'status-1',
                'order_server_id' => $order->id,
                'local_invoice_number' => 'INV-STATUS-1',
                'currency' => 'SAR',
                'total_amount' => 20,
                'payment_method' => 'cash',
            ],
        ], register: false);
        $this->assertTrue($invoice['success']);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem}
     */
    private function bootCashier(): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'برجر',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$token, $workspace, $item];
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
