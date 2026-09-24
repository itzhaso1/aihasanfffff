<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_realtime_config_returns_disabled_when_broadcast_not_configured(): void
    {
        config(['broadcasting.default' => 'null']);

        [$user, $workspace] = $this->member();

        Sanctum::actingAs($user);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/realtime/config')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
