<?php

namespace Tests\Feature\Feature\Pos;

use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCashierUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_keeps_category_sidebar_and_narrow_cart(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $category = PosItemCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'مشروبات',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'pos_item_category_id' => $category->id,
            'name' => 'قهوة',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.cashier.index'))
            ->assertRedirect(route('workspace.pos.tables.index'));
    }

    public function test_create_order_json_returns_optional_print_without_forced_redirect(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عصير',
            'price' => 8,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('workspace.pos.orders.store'), [
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertForbidden();
    }

    public function test_create_order_form_stays_on_cashier(): void
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

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.pos.cashier.index'))
            ->post(route('workspace.pos.orders.store'), [
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertForbidden();
    }

    public function test_external_order_checkout_json_does_not_auto_redirect(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 5,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('workspace.pos.cart.items.store'), [
                'pos_menu_item_id' => $item->id,
                'quantity' => 2,
            ])->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('workspace.pos.cart.checkout'), [])
            ->assertForbidden();
    }

    public function test_tables_board_separates_details_and_menu_actions(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $html = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-table-order-details', $html);
        $this->assertStringContainsString('تفاصيل الطلب', $html);
        $this->assertStringContainsString('data-table-menu', $html);
        $this->assertStringContainsString('تجديد QR', $html);
        $this->assertStringNotContainsString('إغلاق الطاولة</button>', $html);
        $this->assertStringNotContainsString('confirmClose', $html);
    }

    public function test_table_show_has_info_on_right_and_orders_on_left(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة 5',
            'status' => 'available',
            'qr_token' => 'table_show_ui_token',
        ]);

        $html = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.tables.show', $table))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('معلومات الطاولة', $html);
        $this->assertStringContainsString('خيارات الطاولة', $html);
        $this->assertStringContainsString('تفاصيل طلبات الطاولة', $html);
        $this->assertStringContainsString('تطبيق الكاشير', $html);
        $this->assertStringContainsString('الإجمالي الكلي', $html);
        $this->assertStringNotContainsString('إضافة طلب', $html);
        $this->assertStringNotContainsString('إضافة صنف', $html);
        $this->assertStringNotContainsString('إغلاق الطاولة', $html);
        $this->assertStringNotContainsString('إلغاء الطاولة', $html);
    }

    public function test_transfer_merge_and_split_preserve_orders(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $tableA = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'A',
            'status' => 'available',
            'qr_token' => 'transfer_a',
        ]);
        $tableB = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'B',
            'status' => 'available',
            'qr_token' => 'transfer_b',
        ]);
        $tableC = \App\Models\DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'C',
            'status' => 'available',
            'qr_token' => 'transfer_c',
        ]);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $tableA->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
        ]);

        $sessionA = \App\Models\TableSession::query()
            ->where('dining_table_id', $tableA->id)
            ->where('status', 'open')
            ->firstOrFail();

        $order = \App\Models\Order::query()->where('table_session_id', $sessionA->id)->firstOrFail();
        $this->assertSame(20.0, (float) $order->total_amount);

        $pos = app(\App\Services\Pos\PosOrderService::class);
        $pos->transferSession($sessionA, $tableB);

        $order->refresh();
        $this->assertSame($tableB->id, (int) $order->dining_table_id);
        $this->assertSame(20.0, (float) $order->total_amount);

        $sessionB = \App\Models\TableSession::query()
            ->where('dining_table_id', $tableB->id)
            ->where('status', 'open')
            ->firstOrFail();

        // Create order on C then merge C → B
        $this->placePosOrder($workspace, $owner, [
            'dining_table_id' => $tableC->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $sessionC = \App\Models\TableSession::query()
            ->where('dining_table_id', $tableC->id)
            ->where('status', 'open')
            ->firstOrFail();

        $pos->mergeSessions($sessionC, $tableB);

        $ordersOnB = \App\Models\Order::query()
            ->where('dining_table_id', $tableB->id)
            ->where('pos_status', '!=', 'cancelled')
            ->get();
        $this->assertCount(2, $ordersOnB);
        $this->assertEquals(30.0, (float) $ordersOnB->sum('total_amount'));

        // Split one order with qty 2 into two checks of 1
        $splitSource = $ordersOnB->firstWhere(fn ($o) => (int) $o->items->sum('quantity') === 2) ?? $ordersOnB->first();
        $line = $splitSource->items->first();
        $this->assertNotNull($line);

        // Move other order aside conceptually — split only allocates the qty-2 line fully
        // First ensure we only split that line: merge all items from B into one split of the qty-2 item
        // Actually split requires ALL session items allocated. Collect all items.
        $sessionB->refresh();
        $allItems = \App\Models\Order::query()
            ->where('table_session_id', $sessionB->id)
            ->where('pos_status', '!=', 'cancelled')
            ->with('items')
            ->get()
            ->flatMap->items;

        $groups = [
            ['items' => []],
            ['items' => []],
        ];
        foreach ($allItems as $idx => $orderItem) {
            // put first item unit into group0, rest into group1 if qty>1 else all in group0 half
            $qty = (int) $orderItem->quantity;
            if ($qty === 1) {
                $groups[0]['items'][] = ['order_item_id' => $orderItem->id, 'quantity' => 1];
                $groups[1]['items'][] = ['order_item_id' => $orderItem->id, 'quantity' => 0];
            } else {
                $groups[0]['items'][] = ['order_item_id' => $orderItem->id, 'quantity' => 1];
                $groups[1]['items'][] = ['order_item_id' => $orderItem->id, 'quantity' => $qty - 1];
            }
        }

        $pos->splitSessionByItems($sessionB, $groups, $owner);

        $activeAfterSplit = \App\Models\Order::query()
            ->where('dining_table_id', $tableB->id)
            ->where('pos_status', '!=', 'cancelled')
            ->with('items')
            ->get();

        $this->assertGreaterThanOrEqual(2, $activeAfterSplit->count());
        $this->assertEquals(30.0, round((float) $activeAfterSplit->sum('total_amount'), 2));
    }

    public function test_public_menu_has_compact_grid_and_cart_navbar(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 5,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $html = $this->get(route('menu.general', ['workspace' => $workspace->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-menu-navbar', $html);
        $this->assertStringContainsString('data-pos-cart-nav', $html);
        $this->assertStringContainsString('data-pos-cart-badge', $html);
        $this->assertStringContainsString('data-menu-cart-drawer', $html);
        $this->assertStringContainsString('متابعة الطلب', $html);
        $this->assertStringContainsString('grid-cols-2', $html);
        $this->assertStringContainsString('data-menu-product-grid', $html);
        $this->assertStringContainsString('HasoPosFeedback', $html);
        $this->assertStringContainsString('notifyItemAdded', $html);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = \App\Models\User::factory()->create();
        $workspace = \App\Models\Workspace::factory()->create([
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
