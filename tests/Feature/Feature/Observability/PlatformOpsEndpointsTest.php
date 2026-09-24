<?php

namespace Tests\Feature\Feature\Observability;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOpsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_ops_health(): void
    {
        $this->getJson('/platform/ops/health')->assertForbidden();
    }

    public function test_platform_admin_can_read_ops_health_and_metrics(): void
    {
        PlatformAdmin::factory()->create([
            'email' => 'ops@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/platform/login', [
            'email' => 'ops@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/platform/ops/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks', 'queues', 'generated_at'])
            ->assertJsonMissingPath('checks.database.password');

        $this->getJson('/platform/ops/metrics')
            ->assertOk()
            ->assertJsonStructure(['counters_last_hour', 'generated_at']);
    }
}
