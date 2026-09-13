<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PosCashierInvoice;
use App\Models\PosCustomerSession;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierLaravelOriginKitchenInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_pos_takeaway_creates_invoice_and_kitchen_pull_keeps_the_order(): void
    {
        [$token, $owner, $workspace, $item] = $this->boot();

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
        ]);
        $this->assertNotNull($order->pos_cashier_invoice_id);
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertSame(1, Order::query()->count());

        $this->registerDevice($token, $workspace, 'POS-KITCHEN');
        $pull = $this->pullKitchen($token, $workspace);

        $orderSnapshots = collect($pull['changes'] ?? [])
            ->where('entity', 'order')
            ->values();
        $this->assertNotEmpty($orderSnapshots);
        $last = $orderSnapshots->last();
        $this->assertSame($order->id, (int) ($last['id'] ?? 0));
        $this->assertNotEmpty($last['data']['items'] ?? []);
        $this->assertSame('برجر', $last['data']['items'][0]['product_name'] ?? null);
        $this->assertSame([], collect($pull['changes'] ?? [])->where('entity', 'invoice')->all());
    }

    public function test_table_menu_order_reaches_kitchen_pull_without_an_invoice(): void
    {
        [$token, $owner, $workspace, $item] = $this->boot();
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 7',
            'status' => 'available',
            'qr_token' => 'qr-menu-kitchen-7',
        ]);

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
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect();

        $order = Order::query()->where('source', 'qr_menu')->latest('id')->firstOrFail();
        $this->assertSame('new', $order->pos_status);
        $this->assertNull($order->pos_cashier_invoice_id);
        $this->assertSame(0, PosCashierInvoice::query()->count());
        $this->assertNotEmpty($order->items);

        $this->assertTrue(
            PosSyncChange::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('entity_type', 'order')
                ->where('entity_id', $order->id)
                ->exists()
        );

        $this->registerDevice($token, $workspace, 'POS-KITCHEN');
        $pull = $this->pullKitchen($token, $workspace);

        $orderSnapshots = collect($pull['changes'] ?? [])
            ->where('entity', 'order')
            ->filter(fn ($change) => (int) ($change['id'] ?? 0) === (int) $order->id)
            ->values();
        $this->assertNotEmpty($orderSnapshots);
        $last = $orderSnapshots->last();
        $this->assertSame('new', $last['data']['pos_status'] ?? null);
        $this->assertSame('table', $last['data']['order_type'] ?? null);
        $this->assertNotEmpty($last['data']['items'] ?? []);
        $this->assertSame('برجر', $last['data']['items'][0]['product_name'] ?? null);
        $this->assertSame($table->id, (int) ($last['data']['dining_table_id'] ?? 0));
        $this->assertSame([], collect($pull['changes'] ?? [])->where('entity', 'invoice')->all());
    }

    public function test_retrying_web_pos_takeaway_does_not_duplicate_invoice(): void
    {
        [$token, $owner, $workspace, $item] = $this->boot();
        $ref = 'web-pos-idem-'.uniqid();

        $first = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'client_reference' => $ref,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $second = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'client_reference' => $ref,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->pos_cashier_invoice_id, $second->pos_cashier_invoice_id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, PosCashierInvoice::query()->count());
        $this->assertNotNull($token);
    }

    /**
     * @return array{0: string, 1: User, 2: Workspace, 3: PosMenuItem}
     */
    private function boot(): array
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

        return [$token, $owner, $workspace, $item];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullKitchen(string $token, Workspace $workspace): array
    {
        return $this->withToken($token)
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
