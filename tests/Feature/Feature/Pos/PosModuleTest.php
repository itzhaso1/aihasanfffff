<?php

namespace Tests\Feature\Feature\Pos;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_qr_order_flow_creates_order_and_session_with_price_snapshot(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Cappuccino',
            'item_type' => 'مشروبات',
            'price' => 4.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.pos.tables.store'), [
                'name' => 'Table 3',
            ])->assertRedirect();

        $table = DiningTable::query()->where('name', 'Table 3')->firstOrFail();

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertOk();

        $guest = \App\Models\PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'customer_name' => 'Walk In',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 2],
                ],
            ])->assertRedirect();

        $order = Order::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('source', 'qr_menu')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($table->id, (int) $order->dining_table_id);
        $this->assertSame('new', $order->pos_status);
        $this->assertNotNull($order->table_session_id);

        $orderItem = OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(4.0, (float) $orderItem->unit_price);
        $this->assertSame(8.0, (float) $orderItem->total_amount);

        $this->assertDatabaseHas('table_sessions', [
            'workspace_id' => $workspace->id,
            'dining_table_id' => $table->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'occupied',
        ]);

        $item->update(['price' => 11.00]);
        $this->assertSame(4.0, (float) $orderItem->fresh()->unit_price);
    }

    public function test_pos_cashier_order_can_update_status_and_generate_cashier_invoice(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table 1',
            'status' => 'available',
            'qr_token' => 'table_1_token_for_test',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Latte',
            'item_type' => 'مشروبات',
            'price' => 5.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [
                ['pos_menu_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);
        $this->assertNotNull($order->table_session_id);

        $pos = app(\App\Services\Pos\PosOrderService::class);
        $pos->updatePosStatus($order, 'preparing', $owner);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_status' => 'preparing',
        ]);

        $pos->updatePosStatus($order->fresh(), 'completed', $owner);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_status' => 'completed',
            'status' => 'completed',
        ]);

        $session = $order->fresh()->tableSession;
        $this->assertNotNull($session);

        $pos->closeSession($session, (int) $owner->id);

        $cashierInvoiceId = $order->fresh()->pos_cashier_invoice_id;
        $this->assertNotNull($cashierInvoiceId);
        $this->assertDatabaseHas('pos_cashier_invoices', [
            'id' => $cashierInvoiceId,
            'workspace_id' => $workspace->id,
            'dining_table_id' => $table->id,
        ]);
    }

    public function test_cashier_can_create_single_order_with_mixed_item_currencies(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $usdItem = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Espresso',
            'item_type' => 'مشروبات',
            'price' => 3.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $eurItem = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Cookie',
            'item_type' => 'حلويات',
            'price' => 2.50,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'items' => [
                ['pos_menu_item_id' => $usdItem->id, 'quantity' => 1],
                ['pos_menu_item_id' => $eurItem->id, 'quantity' => 2],
            ],
        ]);

        $this->assertSame('MIX', $order->currency);
        $this->assertSame(8.0, (float) $order->subtotal);
        $this->assertSame(8.0, (float) $order->total_amount);
    }

    public function test_workspace_isolation_blocks_cross_workspace_pos_access(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('store');
        [, $workspaceB] = $this->createWorkspaceOwner('store');

        $tableB = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Table B',
            'status' => 'available',
            'qr_token' => 'token_b_table',
        ]);

        $itemB = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Hidden Product',
            'item_type' => 'عام',
            'price' => 9.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.pos.tables.show', $tableB))
            ->assertNotFound();

        $response = $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.pos.orders.store'), [
                'items' => [
                    ['pos_menu_item_id' => $itemB->id, 'quantity' => 1],
                ],
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('table_sessions', 0);
    }

    public function test_pos_item_management_reflects_in_public_menu(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.pos.items.store'), [
                'name' => 'Ice Latte',
                'item_type' => 'مشروبات باردة',
                'price' => 7.5,
                'currency' => 'USD',
                'is_active' => 1,
            ])
            ->assertRedirect();

        $this->get(route('menu.general', ['workspace' => $workspace->slug]))
            ->assertOk()
            ->assertSee('Ice Latte');
    }

    public function test_table_order_items_can_be_edited_and_session_can_close_without_running_order_constraint(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table 7',
            'status' => 'available',
            'qr_token' => 'table_7_token_for_test',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Cheesecake',
            'item_type' => 'حلويات',
            'price' => 8.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [
                ['pos_menu_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);
        $line = $order->items()->firstOrFail();
        $pos = app(\App\Services\Pos\PosOrderService::class);
        $pos->updateOrderItems($order, [
            'discount_amount' => 1.00,
            'items' => [
                ['id' => $line->id, 'quantity' => 3, 'unit_price' => 8.00],
            ],
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $line->id,
            'quantity' => 3,
            'total_amount' => 24.00,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 24.00,
            'discount_amount' => 1.00,
            'total_amount' => 23.00,
        ]);

        $sessionId = (int) $order->table_session_id;
        $session = \App\Models\TableSession::query()->findOrFail($sessionId);
        $pos->closeSession($session, (int) $owner->id);

        $invoice = PosCashierInvoice::query()->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_cashier_invoice_id' => $invoice?->id,
            'pos_status' => 'completed',
        ]);
    }

    public function test_table_session_can_be_cancelled_and_related_orders_are_cancelled(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table 9',
            'status' => 'available',
            'qr_token' => 'table_9_token_for_test',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Fries',
            'item_type' => 'مقبلات',
            'price' => 3.5,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [
                ['pos_menu_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);
        $sessionId = (int) $order->table_session_id;
        $session = \App\Models\TableSession::query()->findOrFail($sessionId);
        app(\App\Services\Pos\PosOrderService::class)->cancelSession($session, $owner);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_status' => 'cancelled',
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('table_sessions', [
            'id' => $sessionId,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'available',
        ]);
    }

    public function test_table_session_discount_can_be_applied_from_table_page(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table 10',
            'status' => 'available',
            'qr_token' => 'table_10_token_for_test',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Pizza',
            'item_type' => 'وجبات',
            'price' => 20.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [
                ['pos_menu_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);
        $sessionId = (int) $order->table_session_id;
        $session = \App\Models\TableSession::query()->findOrFail($sessionId);
        app(\App\Services\Pos\PosOrderService::class)->applySessionDiscount($session, 5);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'discount_amount' => 5.00,
            'total_amount' => 15.00,
        ]);
    }

    public function test_table_view_can_add_new_order_from_table_menu_form(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table 8',
            'status' => 'occupied',
            'qr_token' => 'table_8_token_for_test',
        ]);

        PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Americano',
            'item_type' => 'مشروبات',
            'price' => 4.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $menuItem = PosMenuItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Americano')
            ->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.pos.tables.orders.store', $table), [
                'items' => [
                    ['pos_menu_item_id' => $menuItem->id, 'quantity' => 2],
                ],
            ])
            ->assertForbidden();

        $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [
                ['pos_menu_item_id' => $menuItem->id, 'quantity' => 2],
            ],
        ]);

        $this->assertDatabaseHas('orders', [
            'workspace_id' => $workspace->id,
            'dining_table_id' => $table->id,
            'source' => 'pos',
        ]);
    }

    public function test_kitchen_page_lists_table_orders_for_preparation(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table Kitchen',
            'status' => 'available',
            'qr_token' => 'table_kitchen_token_for_test',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Burger',
            'item_type' => 'وجبات',
            'price' => 12.00,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertOk();
        $guest = \App\Models\PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.kitchen.index'))
            ->assertRedirect(route('workspace.pos.qr-orders.index'));

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.qr-orders.index'))
            ->assertOk()
            ->assertSee('Table Kitchen')
            ->assertSee('Burger');
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
            ->where('code', $workspaceType.'_pro')
            ->first()
            ?? \App\Models\Plan::query()
                ->where('workspace_type', $workspaceType)
                ->where('is_active', true)
                ->orderByDesc('price')
                ->first();

        if ($plan) {
            \App\Models\Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return [$user, $workspace];
    }
}
