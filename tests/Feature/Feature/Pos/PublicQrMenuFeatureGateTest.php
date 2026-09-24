<?php

namespace Tests\Feature\Feature\Pos;

use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PosCustomerSession;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\TableGuestSessionService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class PublicQrMenuFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        $this->fakeAi = new FakeAiProvider;
        $this->app->instance(GoogleAiStudioProvider::class, $this->fakeAi);
        $this->app->instance(OpenAiProvider::class, $this->fakeAi);
        config(['services.google_ai_studio.key' => 'test-not-a-real-key']);
    }

    public function test_pos_enabled_allows_public_general_order(): void
    {
        [$workspace, $item] = $this->seedMenuWorkspace(posEnabled: true);

        $this->from(route('menu.general', ['workspace' => $workspace->slug]))
            ->post(route('menu.general.order', ['workspace' => $workspace->slug]), [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('menu.general', ['workspace' => $workspace->slug]))
            ->assertSessionMissing('error');

        $this->assertSame(1, Order::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_pos_enabled_allows_public_table_order(): void
    {
        [$workspace, $table, $item, $guest] = $this->seedTableMenuWorkspace(posEnabled: true);

        $this->withUnencryptedCookie(
            app(TableGuestSessionService::class)->cookieName($table),
            $guest->token
        )->from(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertSessionMissing('error');

        $this->assertSame(1, Order::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('source', 'qr_menu')
            ->count());
    }

    public function test_pos_disabled_blocks_public_general_order_without_mutations(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class, ProcessAIResponse::class]);

        [$workspace, $item] = $this->seedMenuWorkspace(posEnabled: false);

        $beforeOrders = Order::withoutGlobalScopes()->count();
        $beforePayments = Payment::withoutGlobalScopes()->count();

        $this->from(route('menu.general', ['workspace' => $workspace->slug]))
            ->post(route('menu.general.order', ['workspace' => $workspace->slug]), [
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('menu.general', ['workspace' => $workspace->slug]))
            ->assertSessionHas('error');

        $this->assertSame($beforeOrders, Order::withoutGlobalScopes()->count());
        $this->assertSame($beforePayments, Payment::withoutGlobalScopes()->count());
        Bus::assertNothingDispatched();
    }

    public function test_pos_disabled_blocks_public_table_order_without_mutations(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class, ProcessAIResponse::class]);

        [$workspace, $table, $item, $guest] = $this->seedTableMenuWorkspace(posEnabled: false);

        $beforeOrders = Order::withoutGlobalScopes()->count();

        $this->withUnencryptedCookie(
            app(TableGuestSessionService::class)->cookieName($table),
            $guest->token
        )->from(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->post(route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token]), [
                'guest_session_token' => $guest->token,
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
            ->assertSessionHas('error');

        $this->assertSame($beforeOrders, Order::withoutGlobalScopes()->count());
        Bus::assertNothingDispatched();
    }

    public function test_pos_feature_state_is_isolated_per_workspace_menu(): void
    {
        [$enabledWorkspace, $enabledItem] = $this->seedMenuWorkspace(posEnabled: true, slug: 'menu-enabled-'.uniqid());
        [$disabledWorkspace, $disabledItem] = $this->seedMenuWorkspace(posEnabled: false, slug: 'menu-disabled-'.uniqid());

        $this->post(route('menu.general.order', ['workspace' => $enabledWorkspace->slug]), [
            'items' => [['pos_menu_item_id' => $enabledItem->id, 'quantity' => 1]],
        ])->assertRedirect();
        $this->assertSame(1, Order::withoutGlobalScopes()->where('workspace_id', $enabledWorkspace->id)->count());

        $this->from(route('menu.general', ['workspace' => $disabledWorkspace->slug]))
            ->post(route('menu.general.order', ['workspace' => $disabledWorkspace->slug]), [
                'items' => [['pos_menu_item_id' => $disabledItem->id, 'quantity' => 1]],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(0, Order::withoutGlobalScopes()->where('workspace_id', $disabledWorkspace->id)->count());
    }

    public function test_ai_enabled_allows_public_menu_ai_request(): void
    {
        [$workspace] = $this->seedMenuWorkspace(posEnabled: true, aiEnabled: true);
        $item = PosMenuItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->postJson(route('menu.general.ai', ['workspace' => $workspace->slug]), [
            'message' => 'ما سعر '.$item->name.'؟',
        ])->assertOk()
            ->assertJsonStructure(['answer']);

        $this->assertGreaterThan(0, $this->fakeAi->calls);
    }

    public function test_ai_disabled_rejects_public_menu_ai_without_provider_calls(): void
    {
        [$workspace] = $this->seedMenuWorkspace(posEnabled: true, aiEnabled: false);
        $callsBefore = $this->fakeAi->calls;

        $this->postJson(route('menu.general.ai', ['workspace' => $workspace->slug]), [
            'message' => 'ما المتوفر؟',
        ])->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'ai');

        $this->assertSame($callsBefore, $this->fakeAi->calls);
    }

    public function test_unknown_menu_slug_preserves_not_found_behavior(): void
    {
        $this->get(route('menu.general', ['workspace' => 'missing-menu-slug-'.uniqid()]))
            ->assertNotFound();

        $this->postJson(route('menu.general.ai', ['workspace' => 'missing-menu-slug-'.uniqid()]), [
            'message' => 'hello',
        ])->assertNotFound();
    }

    /**
     * @return array{0: Workspace, 1: PosMenuItem}
     */
    private function seedMenuWorkspace(bool $posEnabled, bool $aiEnabled = true, ?string $slug = null): array
    {
        [, $workspace] = $this->createWorkspaceOwner('store', $slug);
        $this->applyFeatureFlags($workspace, posEnabled: $posEnabled, aiEnabled: $aiEnabled);

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'قهوة',
            'price' => 12,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        return [$workspace, $item];
    }

    /**
     * @return array{0: Workspace, 1: DiningTable, 2: PosMenuItem, 3: PosCustomerSession}
     */
    private function seedTableMenuWorkspace(bool $posEnabled, bool $aiEnabled = true): array
    {
        [$workspace, $item] = $this->seedMenuWorkspace($posEnabled, $aiEnabled);

        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => '5',
            'status' => 'available',
            'qr_token' => 'qr_'.uniqid(),
        ]);

        $session = TableSession::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'dining_table_id' => $table->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $guest = PosCustomerSession::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'dining_table_id' => $table->id,
            'table_session_id' => $session->id,
            'token' => 'guest_'.uniqid(),
            'status' => PosCustomerSession::STATUS_ACTIVE,
            'last_seen_at' => now(),
        ]);

        return [$workspace, $table, $item, $guest];
    }

    private function applyFeatureFlags(Workspace $workspace, bool $posEnabled, bool $aiEnabled): void
    {
        foreach (['pos', 'qr_menu', 'products', 'orders', 'ai'] as $feature) {
            $enabled = match ($feature) {
                'pos' => $posEnabled,
                'ai' => $aiEnabled,
                default => true,
            };

            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                [
                    'workspace_id' => $workspace->id,
                    'feature_key' => $feature,
                    'enabled' => $enabled,
                    'source' => 'manual',
                ]
            );
        }

        app(FeatureAccessService::class)->forgetWorkspaceFeatureCache($workspace->id);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType, ?string $slug = null): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
            'slug' => $slug ?? ('menu-'.uniqid()),
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders', 'ai'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                [
                    'workspace_id' => $workspace->id,
                    'feature_key' => $feature,
                    'enabled' => true,
                    'source' => 'manual',
                ]
            );
        }

        $plan = Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('code', $workspaceType.'_pro')
            ->first()
            ?? Plan::query()
                ->where('workspace_type', $workspaceType)
                ->where('is_active', true)
                ->orderByDesc('price')
                ->first();

        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
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
