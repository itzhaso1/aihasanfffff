<?php

namespace Tests\Feature\Feature\Pos;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosCustomerSession;
use App\Models\PosMenuItem;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTableOrdersUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_show_has_single_add_order_action_without_add_item(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة UX',
            'status' => 'available',
            'qr_token' => 'table_orders_ux_token',
        ]);

        $html = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.show', $table))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('إضافة طلب', $html);
        $this->assertStringNotContainsString('إضافة صنف', $html);
        $this->assertStringNotContainsString("panel = 'addItem'", $html);
        $this->assertStringNotContainsString("panel = 'addOrder'", $html);
        $this->assertStringContainsString('فارغة', $html);
        $this->assertStringContainsString('تطبيق الكاشير', $html);
    }

    public function test_opening_session_keeps_table_empty_until_first_order(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة فارغة',
            'status' => 'available',
            'qr_token' => 'empty_until_order_token',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 5,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.pos.tables.sessions.open', $table))
            ->assertForbidden();

        $this->assertDatabaseMissing('table_sessions', [
            'dining_table_id' => $table->id,
            'status' => 'open',
        ]);
    }

    public function test_qr_menu_visit_does_not_occupy_until_first_order(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة QR',
            'status' => 'available',
            'qr_token' => 'qr_occupy_on_order',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'قهوة',
            'price' => 8,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertOk();

        $this->assertDatabaseHas('table_sessions', [
            'dining_table_id' => $table->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'available',
        ]);

        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->withUnencryptedCookie('pos_guest_'.$table->id, $guest->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'occupied',
        ]);
    }

    public function test_similar_items_on_same_table_merge_quantity_instead_of_new_line(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة دمج',
            'status' => 'available',
            'qr_token' => 'merge_items_token',
        ]);

        $tea = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 3,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $tea->id, 'quantity' => 1]],
        ]);

        $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $tea->id, 'quantity' => 2]],
        ]);

        $session = TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->firstOrFail();
        $this->assertSame(1, Order::query()->where('table_session_id', $session->id)->where('pos_status', '!=', 'cancelled')->count());
        $this->assertSame(1, OrderItem::query()->whereHas('order', fn ($q) => $q->where('table_session_id', $session->id))->count());
        $this->assertDatabaseHas('order_items', [
            'pos_menu_item_id' => $tea->id,
            'quantity' => 3,
            'total_amount' => 9,
        ]);
    }

    public function test_cashier_can_edit_and_delete_order_on_table(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة تعديل',
            'status' => 'available',
            'qr_token' => 'edit_delete_token',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عصير',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $line = $order->items()->firstOrFail();

        $html = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.show', $table))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringNotContainsString('تعديل الطلب', $html);
        $this->assertStringNotContainsString('حذف الطلب', $html);

        $pos = app(\App\Services\Pos\PosOrderService::class);
        $pos->updateOrderItems($order, [
            'items' => [
                ['id' => $line->id, 'quantity' => 4, 'unit_price' => 10],
            ],
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $line->id,
            'quantity' => 4,
            'total_amount' => 40,
        ]);

        $pos->updatePosStatus($order->fresh(), 'cancelled', $owner);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'available',
        ]);
    }

    public function test_removing_last_item_cancels_order_and_frees_table(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة حذف سطر',
            'status' => 'available',
            'qr_token' => 'remove_last_line_token',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'ماء',
            'price' => 2,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $order = $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $table->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $line = $order->items()->firstOrFail();

        app(\App\Services\Pos\PosOrderService::class)->updateOrderItems($order, [
            'items' => [
                ['id' => $line->id, 'quantity' => 1, 'unit_price' => 2, 'remove' => true],
            ],
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pos_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'status' => 'available',
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
            ->where('code', $workspaceType.'_pro')
            ->first()
            ?? \App\Models\Plan::query()
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
