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
use App\Services\Pos\TableGuestSessionService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTableQrSessionTest extends TestCase
{
    use RefreshDatabase;

    private function guestCookie(DiningTable $table, ?PosCustomerSession $guest = null): self
    {
        $guest ??= PosCustomerSession::withoutGlobalScopes()
            ->where('dining_table_id', $table->id)
            ->where('status', PosCustomerSession::STATUS_ACTIVE)
            ->latest('id')
            ->firstOrFail();

        return $this->withUnencryptedCookie(
            app(TableGuestSessionService::class)->cookieName($table),
            $guest->token
        );
    }

    private function closeSessionFromCashier(TableSession $session, User $actor): void
    {
        app(\App\Services\Pos\PosOrderService::class)->closeSession($session, (int) $actor->id);
    }

    public function test_fixed_qr_opens_menu_and_creates_guest_and_table_session(): void
    {
        [$workspace, $table] = $this->seedTableWorkspace();

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertOk()
            ->assertSee('طاولة '.$table->name)
            ->assertSee('guest_session_token', false);

        $this->assertDatabaseCount('table_sessions', 1);
        $this->assertDatabaseCount('pos_customer_sessions', 1);
        $this->assertDatabaseHas('table_sessions', [
            'dining_table_id' => $table->id,
            'status' => 'open',
        ]);
    }

    public function test_refresh_reuses_same_table_and_guest_session(): void
    {
        [$workspace, $table] = $this->seedTableWorkspace();

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $sessionId = (int) $guest->table_session_id;

        $this->get(route('menu.table', [
            'workspace' => $workspace->slug,
            'token' => $table->qr_token,
            'guest_session_token' => $guest->token,
        ]))->assertOk();

        $this->get(route('menu.table', [
            'workspace' => $workspace->slug,
            'token' => $table->qr_token,
            'guest_session_token' => $guest->token,
        ]))->assertOk();

        $this->assertSame(1, TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->count());
        $this->assertSame(1, PosCustomerSession::query()->where('dining_table_id', $table->id)->where('status', 'active')->count());
        $this->assertSame($sessionId, (int) PosCustomerSession::query()->where('dining_table_id', $table->id)->value('table_session_id'));
        $this->assertSame($guest->id, (int) PosCustomerSession::query()->where('dining_table_id', $table->id)->value('id'));
    }

    public function test_valid_guest_session_creates_order_on_correct_table(): void
    {
        [$workspace, $table, $item] = $this->seedTableWorkspace(withItem: true);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->guestCookie($table, $guest)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertRedirect();

        $order = Order::query()->where('source', 'qr_menu')->latest('id')->firstOrFail();
        $this->assertSame($table->id, (int) $order->dining_table_id);
        $this->assertNotNull($order->table_session_id);
        $this->assertSame((int) $guest->table_session_id, (int) $order->table_session_id);
    }

    public function test_closing_table_revokes_guest_sessions(): void
    {
        [$owner, $workspace, $table, $item] = $this->seedOwnedTable(withItem: true);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->guestCookie($table, $guest)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertRedirect();

        $session = TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->firstOrFail();

        $this->closeSessionFromCashier($session, $owner);

        $this->assertDatabaseHas('table_sessions', ['id' => $session->id, 'status' => 'closed']);
        $this->assertDatabaseHas('pos_customer_sessions', [
            'id' => $guest->id,
            'status' => PosCustomerSession::STATUS_REVOKED,
        ]);
    }

    public function test_revoked_guest_session_cannot_create_order(): void
    {
        [$owner, $workspace, $table, $item] = $this->seedOwnedTable(withItem: true);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $session = $guest->tableSession;

        $this->closeSessionFromCashier($session, $owner);

        $ordersBefore = Order::query()->count();

        $this->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
            'guest_session_token' => $guest->token,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('session_expired');

        $this->assertSame($ordersBefore, Order::query()->count());
    }

    public function test_opening_qr_after_close_starts_new_session_with_same_table_token(): void
    {
        [$owner, $workspace, $table, $item] = $this->seedOwnedTable(withItem: true);
        $originalQr = $table->qr_token;

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $originalQr]))->assertOk();
        $oldSessionId = (int) TableSession::query()->where('dining_table_id', $table->id)->value('id');

        $this->closeSessionFromCashier(TableSession::query()->whereKey($oldSessionId)->firstOrFail(), $owner);

        $this->get(route('menu.table', [
            'workspace' => $workspace->slug,
            'token' => $originalQr,
            'fresh' => 1,
        ]))->assertRedirect();

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $originalQr]))
            ->assertOk();

        $table->refresh();
        $this->assertSame($originalQr, $table->qr_token);
        $this->assertSame(1, TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->count());
        $this->assertNotSame(
            $oldSessionId,
            (int) TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->value('id')
        );

        $guest = PosCustomerSession::query()->where('dining_table_id', $table->id)->where('status', 'active')->firstOrFail();
        $this->guestCookie($table, $guest)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $originalQr]), [
                'guest_session_token' => $guest->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertRedirect();
    }

    public function test_qr_token_unchanged_before_and_after_close(): void
    {
        [$owner, $workspace, $table] = $this->seedOwnedTable();
        $before = $table->qr_token;

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $before]))->assertOk();
        $session = TableSession::query()->where('dining_table_id', $table->id)->firstOrFail();

        $this->closeSessionFromCashier($session, $owner);

        $table->refresh();
        $this->assertSame($before, $table->qr_token);
    }

    public function test_table_token_from_another_table_is_rejected(): void
    {
        [$workspace, $tableA] = $this->seedTableWorkspace();
        $tableB = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Table B',
            'status' => 'available',
            'qr_token' => 'other_table_token_xyz',
        ]);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $tableA->qr_token]))->assertOk();
        $guestA = PosCustomerSession::query()->where('dining_table_id', $tableA->id)->firstOrFail();

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Juice',
            'price' => 3,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $tableB->qr_token]), [
            'guest_session_token' => $guestA->token,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('orders', [
            'dining_table_id' => $tableB->id,
            'source' => 'qr_menu',
        ]);
    }

    public function test_multiple_customers_share_table_session_without_losing_orders(): void
    {
        [$workspace, $table, $item] = $this->seedTableWorkspace(withItem: true);

        $this->get(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))->assertOk();
        $guestA = PosCustomerSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $this->guestCookie($table, $guestA)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guestA->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertRedirect();

        $guestB = app(TableGuestSessionService::class)->startFresh($table);
        $this->withUnencryptedCookie(app(TableGuestSessionService::class)->cookieName($table), $guestB->token)
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guestB->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
            ])->assertRedirect();

        $this->assertSame(1, TableSession::query()->where('dining_table_id', $table->id)->where('status', 'open')->count());
        $this->assertSame(2, PosCustomerSession::query()->where('dining_table_id', $table->id)->where('status', 'active')->count());
        $this->assertSame(1, Order::query()->where('dining_table_id', $table->id)->where('source', 'qr_menu')->where('pos_status', '!=', 'cancelled')->count());
        $this->assertSame(3, (int) OrderItem::query()->where('pos_menu_item_id', $item->id)->sum('quantity'));
        $this->assertSame((int) $guestA->table_session_id, (int) $guestB->fresh()->table_session_id);
    }

    public function test_workspace_isolation_blocks_cross_workspace_table_qr(): void
    {
        [, $workspaceA] = $this->createWorkspaceOwner('store');
        [, $workspaceB] = $this->createWorkspaceOwner('store');

        $tableB = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Secret',
            'status' => 'available',
            'qr_token' => 'secret_table_token_abc',
        ]);

        $this->get(route('menu.table', [
            'workspace' => $workspaceA->slug,
            'token' => $tableB->qr_token,
        ]))->assertNotFound();
    }

    /**
     * @return array{0: Workspace, 1: DiningTable, 2?: PosMenuItem}
     */
    private function seedTableWorkspace(bool $withItem = false): array
    {
        $this->seed(FoundationSeeder::class);
        [, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => '5',
            'status' => 'available',
            'qr_token' => 'fixed_qr_table_5_token',
        ]);

        if (! $withItem) {
            return [$workspace, $table];
        }

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 5,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$workspace, $table, $item];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: DiningTable, 3?: PosMenuItem}
     */
    private function seedOwnedTable(bool $withItem = false): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Owned 5',
            'status' => 'available',
            'qr_token' => 'owned_fixed_qr_token',
        ]);

        if (! $withItem) {
            return [$owner, $workspace, $table];
        }

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'قهوة',
            'price' => 8,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$owner, $workspace, $table, $item];
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
