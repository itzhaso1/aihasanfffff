<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\ApiIdempotencyKey;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PosMenuItem;
use App\Models\PosSyncOperation;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSyncHttpIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_sync_push_replays_same_http_idempotency_key_without_second_mutation(): void
    {
        [$token, $workspace, $item] = $this->boot('POS-HTTP-1');
        $operation = $this->orderOperation('op-http-cashier-1', 'http-cashier-sale-1', $item->id);
        $headers = $this->syncHeaders($workspace, 'POS-HTTP-1', 'push-key-cashier-1');
        $payload = [
            'device_id' => 'POS-HTTP-1',
            'operations' => [$operation],
        ];

        $first = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/sync/push', $payload)
            ->assertOk()
            ->json('data');

        $this->assertTrue($first['success']);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'http-cashier-sale-1')->count());

        $second = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/sync/push', $payload)
            ->assertOk()
            ->json();

        $this->assertSame($first, $second['data']);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'http-cashier-sale-1')->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-http-cashier-1')->count());
        $this->assertSame(1, ApiIdempotencyKey::query()->where('key', 'push-key-cashier-1')->where('workspace_id', $workspace->id)->count());
    }

    public function test_same_http_idempotency_key_with_different_payload_returns_conflict(): void
    {
        [$token, $workspace, $item] = $this->boot('POS-HTTP-1B');
        $headers = $this->syncHeaders($workspace, 'POS-HTTP-1B', 'push-key-conflict');
        $payload = [
            'device_id' => 'POS-HTTP-1B',
            'operations' => [$this->orderOperation('op-conflict-a', 'conflict-sale-a', $item->id)],
        ];

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/sync/push', $payload)
            ->assertOk();

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-HTTP-1B',
                'operations' => [$this->orderOperation('op-conflict-b', 'conflict-sale-b', $item->id)],
            ])
            ->assertStatus(409);

        $this->assertSame(1, Order::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_pos_alias_sync_push_replays_same_http_idempotency_key_without_second_mutation(): void
    {
        [$token, $workspace, $item] = $this->boot('POS-HTTP-2');
        $operation = $this->orderOperation('op-http-alias-1', 'http-alias-sale-1', $item->id);
        $headers = $this->syncHeaders($workspace, 'POS-HTTP-2', 'push-key-alias-1');
        $payload = [
            'device_id' => 'POS-HTTP-2',
            'operations' => [$operation],
        ];

        $first = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/pos/sync/push', $payload)
            ->assertOk()
            ->json('data');

        $second = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/pos/sync/push', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'http-alias-sale-1')->count());
    }

    public function test_pos_alias_and_cashier_paths_keep_separate_idempotency_records_for_same_key(): void
    {
        [$token, $workspace, $item] = $this->boot('POS-HTTP-3');
        $headers = $this->syncHeaders($workspace, 'POS-HTTP-3', 'shared-route-key');
        $operation = $this->orderOperation('op-route-a', 'route-a-sale', $item->id);
        $payload = [
            'device_id' => 'POS-HTTP-3',
            'operations' => [$operation],
        ];

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/cashier/v1/sync/push', $payload)
            ->assertOk();

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/pos/sync/push', [
                'device_id' => 'POS-HTTP-3',
                'operations' => [$this->orderOperation('op-route-b', 'route-b-sale', $item->id)],
            ])
            ->assertOk();

        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'route-a-sale')->count());
        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'route-b-sale')->count());
        $this->assertSame(2, ApiIdempotencyKey::query()->where('key', 'shared-route-key')->where('workspace_id', $workspace->id)->count());
    }

    public function test_different_idempotency_keys_allow_independent_sync_push_batches(): void
    {
        [$token, $workspace, $item] = $this->boot('POS-HTTP-4');

        foreach (['key-a', 'key-b'] as $index => $key) {
            $ref = 'independent-sale-'.$index;
            $this->withToken($token)
                ->withHeaders($this->syncHeaders($workspace, 'POS-HTTP-4', $key))
                ->postJson('/api/cashier/v1/sync/push', [
                    'device_id' => 'POS-HTTP-4',
                    'operations' => [$this->orderOperation('op-'.$key, $ref, $item->id)],
                ])
                ->assertOk()
                ->assertJsonPath('data.success', true);
        }

        $this->assertSame(2, Order::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, PosSyncOperation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_idempotency_key_is_scoped_per_workspace(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspaceA] = $this->createWorkspaceOwner('store', slug: 'pos-idem-a');
        $workspaceB = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'store',
            'slug' => 'pos-idem-b',
        ]);
        $workspaceB->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspaceB->id, 'feature_key' => $feature],
                ['workspace_id' => $workspaceB->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspaceA, 'POS-HTTP-A');
        $this->registerDevice($token, $workspaceB, 'POS-HTTP-B');

        $itemA = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'شاي',
            'price' => 10,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $itemB = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'ماء',
            'price' => 3,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $sharedKey = 'shared-workspace-key';

        $this->withToken($token)
            ->withHeaders($this->syncHeaders($workspaceA, 'POS-HTTP-A', $sharedKey))
            ->postJson('/api/pos/sync/push', [
                'device_id' => 'POS-HTTP-A',
                'operations' => [$this->orderOperation('op-ws-a', 'ws-a-sale', $itemA->id)],
            ])
            ->assertOk();

        $this->withToken($token)
            ->withHeaders($this->syncHeaders($workspaceB, 'POS-HTTP-B', $sharedKey))
            ->postJson('/api/pos/sync/push', [
                'device_id' => 'POS-HTTP-B',
                'operations' => [$this->orderOperation('op-ws-b', 'ws-b-sale', $itemB->id)],
            ])
            ->assertOk();

        $this->assertSame(1, Order::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(1, Order::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(2, ApiIdempotencyKey::query()->where('key', $sharedKey)->count());
    }

    public function test_same_operation_uuid_replay_still_deduplicates_without_http_idempotency_key(): void
    {
        [$token, $workspace, $item] = $this->boot('POS-HTTP-5');
        $operation = $this->orderOperation('op-domain-dedup', 'domain-dedup-sale', $item->id);
        $headers = [
            'X-Workspace-Id' => (string) $workspace->id,
            'X-Device-Id' => 'POS-HTTP-5',
        ];
        $payload = [
            'device_id' => 'POS-HTTP-5',
            'operations' => [$operation],
        ];

        $this->withToken($token)->withHeaders($headers)->postJson('/api/pos/sync/push', $payload)->assertOk();
        $this->withToken($token)->withHeaders($headers)->postJson('/api/pos/sync/push', $payload)->assertOk();

        $this->assertSame(1, Order::withoutGlobalScopes()->where('client_reference', 'domain-dedup-sale')->count());
        $this->assertSame(1, PosSyncOperation::withoutGlobalScopes()->where('operation_uuid', 'op-domain-dedup')->count());
    }

    public function test_pos_alias_pull_replays_with_http_idempotency_key(): void
    {
        [$token, $workspace] = $this->boot('POS-HTTP-6', withItem: false);
        $headers = $this->syncHeaders($workspace, 'POS-HTTP-6', 'pull-key-1');
        $payload = [
            'device_id' => 'POS-HTTP-6',
            'cursor' => 0,
            'limit' => 5,
        ];

        $first = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/pos/sync/pull', $payload)
            ->assertOk()
            ->json();

        $second = $this->withToken($token)
            ->withHeaders($headers)
            ->postJson('/api/pos/sync/pull', $payload)
            ->assertOk()
            ->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, ApiIdempotencyKey::query()->where('key', 'pull-key-1')->where('workspace_id', $workspace->id)->count());
    }

    /**
     * @return array{0: string, 1: Workspace, 2: PosMenuItem|null}
     */
    private function boot(string $deviceId, bool $withItem = true, ?string $slug = null): array
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store', $slug);
        $token = $this->loginToken($owner);
        $this->registerDevice($token, $workspace, $deviceId);

        $item = null;
        if ($withItem) {
            $item = PosMenuItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'شاي',
                'price' => 10,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
        }

        return [$token, $workspace, $item];
    }

    /**
     * @return array<string, string>
     */
    private function syncHeaders(Workspace $workspace, string $deviceId, string $idempotencyKey): array
    {
        return [
            'X-Workspace-Id' => (string) $workspace->id,
            'X-Device-Id' => $deviceId,
            'Idempotency-Key' => $idempotencyKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderOperation(string $uuid, string $clientReference, int $itemId): array
    {
        return [
            'id' => $uuid,
            'type' => 'order.created',
            'data' => [
                'order_type' => 'takeaway',
                'client_reference' => $clientReference,
                'items' => [
                    ['pos_menu_item_id' => $itemId, 'quantity' => 1],
                ],
            ],
        ];
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
        return (string) $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'POS sync idempotency test',
            'device_type' => 'cashier',
        ])->assertOk()->json('data.token');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType, ?string $slug = null): array
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $attributes = [
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ];
        if ($slug !== null) {
            $attributes['slug'] = $slug;
        }
        $workspace = Workspace::factory()->create($attributes);
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
