<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\DiningTable;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPhase1BSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_items_expose_last_page_and_return_every_page(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);
        $category = PosItemCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'وجبات 1ب',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        for ($i = 1; $i <= 120; $i++) {
            PosMenuItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'pos_item_category_id' => $category->id,
                'name' => 'صنف '.$i,
                'price' => 1.5,
                'currency' => 'SAR',
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        $page1 = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/catalog/items?page=1&per_page=100&active_only=0')
            ->assertOk();

        $this->assertSame(1, (int) $page1->json('meta.current_page'));
        $this->assertSame(100, (int) $page1->json('meta.per_page'));
        $this->assertSame(120, (int) $page1->json('meta.total'));
        $this->assertSame(2, (int) $page1->json('meta.last_page'));
        $this->assertCount(100, $page1->json('data.items'));

        $page2 = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/catalog/items?page=2&per_page=100&active_only=0')
            ->assertOk();

        $this->assertSame(2, (int) $page2->json('meta.current_page'));
        $this->assertCount(20, $page2->json('data.items'));
        $ids = array_merge(
            array_column($page1->json('data.items'), 'id'),
            array_column($page2->json('data.items'), 'id'),
        );
        $this->assertCount(120, array_unique($ids));
    }

    public function test_tables_index_can_page_past_the_legacy_100_cap(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        for ($i = 1; $i <= 120; $i++) {
            DiningTable::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'T-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'status' => 'available',
                'qr_token' => 'qr-'.$i.'-'.bin2hex(random_bytes(8)),
            ]);
        }

        $unpaged = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/tables')
            ->assertOk();
        $this->assertCount(100, $unpaged->json('data.tables'));
        $this->assertNull($unpaged->json('meta'));

        $page1 = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/tables?page=1&per_page=100')
            ->assertOk();
        $this->assertSame(120, (int) $page1->json('meta.total'));
        $this->assertSame(2, (int) $page1->json('meta.last_page'));
        $this->assertCount(100, $page1->json('data.tables'));

        $page2 = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/tables?page=2&per_page=100')
            ->assertOk();
        $this->assertCount(20, $page2->json('data.tables'));
    }

    public function test_sync_pull_limit_zero_returns_integer_server_cursor_without_changes(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'phase1b-device',
                'name' => 'كاشير حاسم',
                'platform' => 'cashier',
            ])
            ->assertOk();

        PosSyncChange::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'entity_type' => 'product',
            'entity_id' => 15,
            'operation' => 'create',
            'origin_device_id' => 'other-device',
            'payload' => ['id' => 15, 'name' => 'برجر'],
            'created_at' => now(),
        ]);
        $head = (int) PosSyncChange::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->max('id');

        $pull = $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'phase1b-device',
            ])
            ->postJson('/api/cashier/v1/sync/pull', [
                'device_id' => 'phase1b-device',
                'cursor' => 0,
                'limit' => 0,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame($head, (int) $pull['server_cursor']);
        $this->assertSame($head, (int) $pull['cursor']);
        $this->assertFalse((bool) $pull['has_more']);
        $this->assertSame([], $pull['changes']);
        $this->assertIsInt($pull['server_cursor']);
        $this->assertDoesNotMatchRegularExpression('/T/', (string) $pull['server_cursor']);
    }

    public function test_bootstrap_still_returns_when_pos_is_disabled(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->enableWorkspaceFeature($workspace, 'pos', false);
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.pos_enabled', false);
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
