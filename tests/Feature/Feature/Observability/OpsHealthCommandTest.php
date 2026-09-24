<?php

namespace Tests\Feature\Feature\Observability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpsHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_health_json_exits_zero_when_database_ok(): void
    {
        $code = Artisan::call('ops:health', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertContains($payload['status'], ['healthy', 'degraded', 'unhealthy']);
        $this->assertSame(0, $code);
    }
}
