<?php

namespace Tests\Feature\Feature\Security;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);

        RateLimiter::for('legacy-api', function (Request $request) {
            return Limit::perMinute(3)->by('legacy-api-test:'.(string) ($request->user()?->id ?: $request->ip()));
        });
    }

    public function test_legacy_api_allows_requests_under_limit_and_returns_headers(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();

        $response = $this->actingAsApi($owner, $workspace)
            ->getJson('/api/customers')
            ->assertOk();

        $response->assertHeader('X-Ratelimit-Limit');
        $response->assertHeader('X-Ratelimit-Remaining');
    }

    public function test_legacy_api_returns_429_when_limit_is_exceeded(): void
    {
        [$owner, $workspace] = $this->makeWorkspace();
        $client = $this->actingAsApi($owner, $workspace);

        for ($i = 0; $i < 3; $i++) {
            $client->getJson('/api/customers')->assertOk();
        }

        $client->getJson('/api/customers')
            ->assertStatus(429)
            ->assertHeader('X-Ratelimit-Limit')
            ->assertHeader('Retry-After');
    }

    public function test_legacy_api_limiter_is_isolated_per_user(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Legacy RL A');
        [$ownerB, $workspaceB] = $this->makeWorkspace('Legacy RL B');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsApi($ownerA, $workspaceA)
                ->getJson('/api/customers')
                ->assertOk();
        }

        $this->actingAsApi($ownerA, $workspaceA)
            ->getJson('/api/customers')
            ->assertStatus(429);

        $this->actingAsApi($ownerB, $workspaceB)
            ->getJson('/api/customers')
            ->assertOk();
    }

    public function test_legacy_auth_login_keeps_dedicated_throttle(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy-rl-login@example.com',
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', [
                'email_or_phone' => 'legacy-rl-login@example.com',
                'password' => 'wrong-password',
                'workspace_id' => $workspace->id,
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email_or_phone' => 'legacy-rl-login@example.com',
            'password' => 'wrong-password',
            'workspace_id' => $workspace->id,
        ])->assertStatus(429);
    }

    public function test_legacy_ai_reply_uses_stricter_mobile_ai_limiter(): void
    {
        RateLimiter::for('mobile-ai', function (Request $request) {
            return Limit::perMinute(2)->by('mobile-ai-test:'.(string) ($request->user()?->id ?: $request->ip()));
        });

        [$owner, $workspace] = $this->makeWorkspace('Legacy AI RL');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $client = $this->actingAsApi($owner, $workspace);

        for ($i = 0; $i < 2; $i++) {
            $client->postJson('/api/ai/reply', [
                'conversation_id' => 999999,
                'message_id' => 999999,
            ])->assertStatus(404);
        }

        $client->postJson('/api/ai/reply', [
            'conversation_id' => 999999,
            'message_id' => 999999,
        ])->assertStatus(429);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name = 'Legacy RL Workspace'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function actingAsApi(User $user, Workspace $workspace)
    {
        $this->flushHeaders();
        Sanctum::actingAs($user);

        return $this->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
