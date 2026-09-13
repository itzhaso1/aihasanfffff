<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PosDevice;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\PosSyncChange;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierSyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_device_is_idempotent(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        $first = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'device-abc-001',
                'name' => 'كاشير 1',
                'platform' => 'cashier',
            ])
            ->assertOk()
            ->assertJsonPath('data.device_id', 'device-abc-001')
            ->assertJsonPath('data.workspace_id', $workspace->id)
            ->assertJsonPath('data.account_id', $owner->id);

        $second = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'device-abc-001',
                'name' => 'كاشير 1 محدّث',
            ])
            ->assertOk()
            ->assertJsonPath('data.device_id', 'device-abc-001')
            ->assertJsonPath('data.workspace_id', $workspace->id);

        $this->assertSame(1, PosDevice::withoutGlobalScopes()->count());
        $this->assertSame('كاشير 1 محدّث', $second->json('data.name'));
        $this->assertNotEmpty($first->json('data.registered_at'));
    }

    public function test_device_cannot_register_into_another_workspace(): void
    {
        $this->seed(FoundationSeeder::class);
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('store');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('store');
        $tokenA = $this->loginToken($ownerA);

        $this->withToken($tokenA)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceA->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'shared-device-xyz',
            ])
            ->assertOk();

        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $tokenB = $this->loginToken($ownerB);

        $this->withToken($tokenB)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceB->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'shared-device-xyz',
            ])
            ->assertStatus(403);
    }

    public function test_sync_changes_are_incremental_ordered_and_workspace_scoped(): void
    {
        $this->seed(FoundationSeeder::class);
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('store');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('store');
        $tokenA = $this->loginToken($ownerA);

        $empty = $this->withToken($tokenA)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceA->id])
            ->getJson('/api/cashier/v1/sync/changes?since=0&limit=0')
            ->assertOk()
            ->json('data');
        $this->assertSame([], $empty['changes']);
        $cursor0 = (int) $empty['cursor'];

        $category = PosItemCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'مشروبات',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'pos_item_category_id' => $category->id,
            'name' => 'شاي',
            'price' => 5,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $item->update(['price' => 6]);
        $table = DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'T1',
            'status' => 'available',
            'qr_token' => 'qr-1',
        ]);
        $item->delete();

        // Noise in workspace B must never leak.
        PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'سرّي',
            'price' => 99,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $pull = $this->withToken($tokenA)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceA->id])
            ->getJson('/api/cashier/v1/sync/changes?since='.$cursor0.'&limit=200')
            ->assertOk()
            ->json('data');

        $ops = array_map(fn ($c) => $c['entity'].':'.$c['operation'], $pull['changes']);
        $this->assertSame([
            'category:create',
            'product:create',
            'product:update',
            'table:create',
            'product:delete',
        ], $ops);

        $versions = array_column($pull['changes'], 'version');
        $sorted = $versions;
        sort($sorted);
        $this->assertSame($sorted, $versions);
        $this->assertSame(end($versions), $pull['cursor']);

        foreach ($pull['changes'] as $change) {
            $row = PosSyncChange::withoutGlobalScopes()->findOrFail($change['version']);
            $this->assertSame($workspaceA->id, (int) $row->workspace_id);
        }

        // Current cursor returns empty.
        $again = $this->withToken($tokenA)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceA->id])
            ->getJson('/api/cashier/v1/sync/changes?since='.$pull['cursor'])
            ->assertOk()
            ->json('data');
        $this->assertSame([], $again['changes']);
        $this->assertSame($pull['cursor'], $again['cursor']);

        // Switch auth principal cleanly before asserting workspace isolation.
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $tokenB = $this->loginToken($ownerB);
        $leak = $this->withToken($tokenB)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceB->id])
            ->getJson('/api/cashier/v1/sync/changes?since=0')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $leak['changes']);
        $this->assertSame('سرّي', data_get($leak['changes'][0], 'data.name'));
        $entityIds = array_column($leak['changes'], 'id');
        $this->assertNotContains($item->id, $entityIds);
        $this->assertNotContains($category->id, $entityIds);
        $this->assertNotContains($table->id, $entityIds);
    }

    public function test_long_offline_gap_pulls_only_delta_not_full_catalog(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        // Day 1 baseline products.
        for ($i = 0; $i < 5; $i++) {
            PosMenuItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'قديم-'.$i,
                'price' => 1 + $i,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        }

        $baseline = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/sync/changes?since=0&limit=0')
            ->assertOk()
            ->json('data');
        $cursor = (int) $baseline['server_cursor'];
        $this->assertGreaterThan(0, $cursor);

        // Day 1→3 server changes while device offline.
        $updated = PosMenuItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();
        $updated->update(['name' => 'محدّث']);
        PosItemCategory::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'فئة جديدة',
            'is_active' => true,
            'sort_order' => 9,
        ]);
        DiningTable::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'طاولة جديدة',
            'status' => 'available',
            'qr_token' => 'qr-new',
        ]);

        $delta = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/sync/changes?since='.$cursor)
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $delta['changes']);
        $this->assertLessThan(
            PosMenuItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
            count($delta['changes'])
        );
    }

    public function test_order_client_reference_remains_idempotent(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $token = $this->loginToken($owner);
        $clientRef = 'idem-sync-'.uniqid();

        $payload = [
            'order_type' => 'takeaway',
            'client_reference' => $clientRef,
            'items' => [
                ['pos_menu_item_id' => $item->id, 'quantity' => 1],
            ],
        ];

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'Idempotency-Key' => $clientRef,
            ])
            ->postJson('/api/cashier/v1/orders', $payload)
            ->assertCreated();

        // Different HTTP Idempotency-Key; same client_reference (domain idempotency).
        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'Idempotency-Key' => $clientRef.'-retry',
            ])
            ->postJson('/api/cashier/v1/orders', $payload)
            ->assertOk();

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Order::query()->where('client_reference', $clientRef)->count()));
    }

    public function test_order_and_customer_changes_appear_in_sync_log(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        $baseline = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/sync/changes?since=0&limit=0')
            ->assertOk()
            ->json('data');
        $cursor = (int) $baseline['server_cursor'];

        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'device-sync-1',
                'Idempotency-Key' => 'ord-sync-1',
            ])
            ->postJson('/api/cashier/v1/orders', [
                'order_type' => 'takeaway',
                'client_reference' => 'ord-sync-1',
                'items' => [
                    ['pos_menu_item_id' => $item->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'X-Device-Id' => 'device-sync-1',
                'Idempotency-Key' => 'cust-sync-1',
            ])
            ->postJson('/api/cashier/v1/customers', [
                'name' => 'عميل مزامنة',
                'phone' => '0599999999',
                'client_reference' => 'cust-sync-1',
            ])
            ->assertCreated();

        // Idempotent customer replay.
        $this->withToken($token)
            ->withHeaders([
                'X-Workspace-Id' => (string) $workspace->id,
                'Idempotency-Key' => 'cust-sync-1-retry',
            ])
            ->postJson('/api/cashier/v1/customers', [
                'name' => 'عميل مزامنة',
                'phone' => '0599999999',
                'client_reference' => 'cust-sync-1',
            ])
            ->assertOk();

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Customer::query()->where('client_reference', 'cust-sync-1')->count()));

        $pull = $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/cashier/v1/sync/changes?since='.$cursor)
            ->assertOk()
            ->json('data');

        $entities = array_column($pull['changes'], 'entity');
        $this->assertContains('order', $entities);
        $this->assertContains('customer', $entities);
        $this->assertContains('product', $entities);

        foreach ($pull['changes'] as $change) {
            if ($change['entity'] === 'order') {
                $this->assertArrayHasKey('items', $change['data']);
                $this->assertSame('ord-sync-1', $change['data']['client_reference']);
                $this->assertSame('device-sync-1', $change['origin_device_id']);
            }
        }
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
}
