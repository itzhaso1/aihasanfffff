<?php

namespace Tests\Feature\Feature\Pos;

use App\Events\NewMenuOrderCreated;
use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PosCustomerSession;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PosFinalCashierCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_takeaway_order_type_does_not_open_table_session(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace);

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $this->assertSame('takeaway', $order->order_type);
        $this->assertSame('takeaway', $order->order_type);
        $this->assertNull($order->dining_table_id);
        $this->assertNull($order->table_session_id);
        $this->assertDatabaseCount('table_sessions', 0);
    }

    public function test_table_order_type_binds_session_and_creates_order(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace);
        $table = $this->makeTable($workspace, 'T-1');

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'table',
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
        ]);
        $this->assertSame('table', $order->order_type);
        $this->assertSame($table->id, (int) $order->dining_table_id);
        $this->assertNotNull($order->table_session_id);
    }

    public function test_tax_is_calculated_from_workspace_settings_in_backend(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $workspace->update(['settings' => ['pos' => ['tax_rate' => 10]]]);
        $item = $this->makeItem($workspace, price: 100);

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'discount_amount' => 0,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(10.0, (float) $order->tax_amount);
        $this->assertSame(110.0, (float) $order->total_amount);
    }

    public function test_discount_percent_is_applied_server_side(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace, price: 50);

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'discount_percent' => 20,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
        ]);
        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(20.0, (float) $order->discount_amount);
        $this->assertSame(80.0, (float) $order->total_amount);
    }

    public function test_creating_takeaway_order_creates_cashier_invoice(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace);

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $invoiceId = $order->pos_cashier_invoice_id;
        $this->assertNotNull($invoiceId);
        $this->assertDatabaseCount('pos_cashier_invoices', 1);
        $this->assertSame('completed', $order->pos_status);
        $this->assertDatabaseHas('pos_cashier_invoices', [
            'id' => $invoiceId,
            'workspace_id' => $workspace->id,
        ]);
        $this->assertDatabaseHas('pos_cashier_invoice_items', [
            'pos_cashier_invoice_id' => $invoiceId,
        ]);
    }

    public function test_creating_table_order_does_not_auto_create_invoice(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace);
        $table = $this->makeTable($workspace, 'T-INV');

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'table',
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertDatabaseCount('pos_cashier_invoices', 0);
        $this->assertNotNull($order->table_session_id);
        $this->assertNull($order->pos_cashier_invoice_id);
        $this->assertSame('new', $order->pos_status);
    }

    public function test_menu_order_idempotency_with_client_reference(): void
    {
        $this->seed(FoundationSeeder::class);
        [, $workspace] = $this->createWorkspaceOwner('store');
        $table = $this->makeTable($workspace, '5');
        $item = $this->makeItem($workspace);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $payload = [
            'guest_session_token' => $guest->token,
            'client_reference' => 'idem-ref-12345',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ];

        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), $payload)
            ->assertRedirect();

        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), $payload)
            ->assertRedirect();

        $this->assertSame(1, Order::query()->where('client_reference', 'idem-ref-12345')->count());
    }

    public function test_new_menu_order_dispatches_event(): void
    {
        Event::fake([NewMenuOrderCreated::class]);

        $this->seed(FoundationSeeder::class);
        [, $workspace] = $this->createWorkspaceOwner('store');
        $table = $this->makeTable($workspace, '9');
        $item = $this->makeItem($workspace);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'client_reference' => 'evt-ref-1',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect();

        Event::assertDispatched(NewMenuOrderCreated::class);
    }

    public function test_transfer_moves_guest_sessions_and_writes_audit(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $tableA = $this->makeTable($workspace, 'A');
        $tableB = $this->makeTable($workspace, 'B');
        $item = $this->makeItem($workspace);

        $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $tableA->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $session = \App\Models\TableSession::query()->where('dining_table_id', $tableA->id)->where('status', 'open')->firstOrFail();
        PosCustomerSession::query()->create([
            'workspace_id' => $workspace->id,
            'dining_table_id' => $tableA->id,
            'table_session_id' => $session->id,
            'token' => str_repeat('a', 64),
            'status' => PosCustomerSession::STATUS_ACTIVE,
            'last_seen_at' => now(),
        ]);

        app(\App\Services\Pos\PosOrderService::class)->transferSession($session, $tableB);

        $this->assertDatabaseHas('pos_customer_sessions', [
            'dining_table_id' => $tableB->id,
            'status' => PosCustomerSession::STATUS_ACTIVE,
        ]);
        $this->assertTrue(
            AuditLog::withoutGlobalScopes()->where('action', 'pos.table_session.transferred')->exists()
        );
    }

    public function test_sku_barcode_search_fields_available_on_cashier(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'ماء',
            'sku' => 'WATER-1',
            'barcode' => '628100000001',
            'price' => 2,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.cashier.index'))
            ->assertRedirect(route('workspace.pos.tables.index'));
    }

    public function test_live_tables_board_shows_occupied_after_menu_order_without_manual_refresh(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $table = $this->makeTable($workspace, 'Live');
        $item = $this->makeItem($workspace);

        $html = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('liveBoardUrl', $html);
        $this->assertStringContainsString('refreshBoard', $html);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'client_reference' => 'live-board-ref-1',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'occupied',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->getJson(route('workspace.pos.tables.live'))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $table->id,
                'status' => 'occupied',
            ]);
    }

    public function test_cashier_dashboard_shows_order_channel_counts(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace);
        $table = $this->makeTable($workspace, 'Stats');

        $this->placePosOrder($workspace, $owner, [
            'order_type' => 'table',
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $this->placePosOrder($workspace, $owner, [
            'order_type' => 'delivery',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $html = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('داخل المطعم', $html);
        $this->assertStringContainsString('طلب خارجي', $html);
        $this->assertStringContainsString('توصيل', $html);
        $this->assertStringContainsString('data-pos-order-channel-stats', $html);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->getJson(route('workspace.pos.orders.channel-stats'))
            ->assertOk()
            ->assertJsonPath('stats.table', 1)
            ->assertJsonPath('stats.takeaway', 1)
            ->assertJsonPath('stats.delivery', 1)
            ->assertJsonPath('stats.total', 3);
    }

    private function makeItem(Workspace $workspace, float $price = 10): PosMenuItem
    {
        return PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'منتج',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    private function makeTable(Workspace $workspace, string $name): DiningTable
    {
        return DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'available',
            'qr_token' => 'qr_'.strtolower($name).'_'.uniqid(),
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
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
