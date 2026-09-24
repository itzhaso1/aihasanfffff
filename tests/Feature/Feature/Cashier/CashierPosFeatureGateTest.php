<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashierPosFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_pos_enabled_allows_sync_changes_push_and_pull(): void
    {
        [$owner, $workspace] = $this->createCashierWorkspace(posEnabled: true);
        $token = $this->loginToken($owner);
        $headers = $this->workspaceHeaders($workspace);
        $this->registerDevice($token, $workspace, 'POS-GATE-1');

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/cashier/v1/sync/changes?since=0&limit=10')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->withHeaders($headers + ['X-Device-Id' => 'POS-GATE-1'])
            ->postJson('/api/cashier/v1/sync/push', [
                'device_id' => 'POS-GATE-1',
                'operations' => [
                    [
                        'id' => '11111111-1111-4111-8111-111111111111',
                        'type' => 'category.created',
                        'data' => ['name' => 'POS Gate Category'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->withHeaders($headers + ['X-Device-Id' => 'POS-GATE-1'])
            ->postJson('/api/cashier/v1/sync/pull', [
                'device_id' => 'POS-GATE-1',
                'cursor' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->withHeaders($headers + ['X-Device-Id' => 'POS-GATE-1'])
            ->postJson('/api/pos/sync/push', [
                'device_id' => 'POS-GATE-1',
                'operations' => [
                    [
                        'id' => '22222222-2222-4222-8222-222222222222',
                        'type' => 'category.created',
                        'data' => ['name' => 'POS Alias Category'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->withHeaders($headers + ['X-Device-Id' => 'POS-GATE-1'])
            ->postJson('/api/pos/sync/pull', [
                'device_id' => 'POS-GATE-1',
                'cursor' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[DataProvider('syncEndpointProvider')]
    public function test_pos_disabled_blocks_sync_endpoints_for_owner(string $method, string $uri, ?array $payload): void
    {
        [$owner, $workspace] = $this->createCashierWorkspace(posEnabled: false);
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->withHeaders($this->workspaceHeaders($workspace))
            ->json($method, $uri, $payload ?? [])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.pos_enabled', false);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?array<string, mixed>}>
     */
    public static function syncEndpointProvider(): array
    {
        return [
            'cashier changes' => ['GET', '/api/cashier/v1/sync/changes?since=0', null],
            'cashier push' => ['POST', '/api/cashier/v1/sync/push', [
                'device_id' => 'POS-DISABLED',
                'operations' => [
                    [
                        'id' => '33333333-3333-4333-8333-333333333333',
                        'type' => 'category.created',
                        'data' => ['name' => 'Blocked Category'],
                    ],
                ],
            ]],
            'cashier pull' => ['POST', '/api/cashier/v1/sync/pull', [
                'device_id' => 'POS-DISABLED',
                'cursor' => 0,
            ]],
            'pos alias push' => ['POST', '/api/pos/sync/push', [
                'device_id' => 'POS-DISABLED',
                'operations' => [
                    [
                        'id' => '44444444-4444-4444-8444-444444444444',
                        'type' => 'category.created',
                        'data' => ['name' => 'Blocked Alias Category'],
                    ],
                ],
            ]],
            'pos alias pull' => ['POST', '/api/pos/sync/pull', [
                'device_id' => 'POS-DISABLED',
                'cursor' => 0,
            ]],
        ];
    }

    public function test_pos_disabled_blocks_sync_for_elevated_cashier_member(): void
    {
        [$owner, $workspace] = $this->createCashierWorkspace(posEnabled: false);
        $agent = User::factory()->create(['password' => bcrypt('password')]);
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $token = $this->loginToken($agent);

        $this->withToken($token)
            ->withHeaders($this->workspaceHeaders($workspace))
            ->getJson('/api/cashier/v1/sync/changes?since=0')
            ->assertForbidden()
            ->assertJsonPath('meta.pos_enabled', false);
    }

    public function test_non_member_is_rejected_before_pos_feature_check(): void
    {
        [, $workspace] = $this->createCashierWorkspace(posEnabled: true);
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->getJson('/api/cashier/v1/sync/changes?since=0')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCashierWorkspace(bool $posEnabled): array
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'store',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                [
                    'workspace_id' => $workspace->id,
                    'feature_key' => $feature,
                    'enabled' => $feature === 'pos' ? $posEnabled : true,
                    'source' => 'manual',
                ]
            );
        }

        $plan = Plan::query()
            ->where('workspace_type', 'store')
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

    private function loginToken(User $user): string
    {
        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $user->email,
            'password' => 'password',
            'device_name' => 'Cashier POS gate test',
            'device_type' => 'cashier',
        ])->assertOk();

        return (string) $login->json('data.token');
    }

    private function registerDevice(string $token, Workspace $workspace, string $deviceId): void
    {
        $this->withToken($token)
            ->withHeaders($this->workspaceHeaders($workspace))
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => $deviceId,
                'name' => $deviceId,
                'platform' => 'cashier',
            ])
            ->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeaders(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
