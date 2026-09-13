<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\PosDevice;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPhase1AAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_success_returns_token_user_workspaces_and_pos_flag(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');

        $login = $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
            'device_name' => 'كاشير حاسم',
            'device_type' => 'cashier',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.pos_enabled', true)
            ->assertJsonPath('data.pos_enabled', true);

        $this->assertNotEmpty($login->json('data.token'));
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner] = $this->createWorkspaceOwner('store');

        $this->postJson('/api/cashier/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'wrong-password',
            'device_name' => 'كاشير حاسم',
            'device_type' => 'cashier',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
    }

    public function test_workspace_index_includes_pos_enabled_for_selection(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->getJson('/api/cashier/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.pos_enabled', true);
    }

    public function test_device_register_rejected_when_pos_disabled(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->enableWorkspaceFeature($workspace, 'pos', false);
        $token = $this->loginToken($owner);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'device-pos-off',
                'name' => 'كاشير حاسم',
                'platform' => 'cashier',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'الكاشير غير متاح في باقتك الحالية')
            ->assertJsonPath('meta.pos_enabled', false);

        $this->assertSame(0, PosDevice::withoutGlobalScopes()->count());
    }

    public function test_device_register_success_repeat_and_other_workspace(): void
    {
        $this->seed(FoundationSeeder::class);
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('store');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('store');
        $tokenA = $this->loginToken($ownerA);

        $first = $this->withToken($tokenA)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceA->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'phase1a-device-1',
                'name' => 'كاشير حاسم',
                'platform' => 'cashier',
            ])
            ->assertOk()
            ->assertJsonPath('data.device_id', 'phase1a-device-1')
            ->assertJsonPath('data.workspace_id', $workspaceA->id)
            ->assertJsonPath('data.user_id', $ownerA->id);

        $this->withToken($tokenA)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceA->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'phase1a-device-1',
                'name' => 'كاشير حاسم',
                'platform' => 'cashier',
            ])
            ->assertOk()
            ->assertJsonPath('data.device_id', 'phase1a-device-1');

        $this->assertSame(1, PosDevice::withoutGlobalScopes()->count());
        $this->assertNotEmpty($first->json('data.registered_at'));

        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $tokenB = $this->loginToken($ownerB);
        $this->withToken($tokenB)
            ->withHeaders(['X-Workspace-Id' => (string) $workspaceB->id])
            ->postJson('/api/cashier/v1/devices/register', [
                'device_id' => 'phase1a-device-1',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'هذا الجهاز مسجّل لمساحة عمل أخرى ولا يمكن إعادة استخدامه.');
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
