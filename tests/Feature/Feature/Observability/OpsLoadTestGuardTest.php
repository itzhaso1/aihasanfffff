<?php

namespace Tests\Feature\Feature\Observability;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpsLoadTestGuardTest extends TestCase
{
    public function test_load_test_refuses_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $code = Artisan::call('ops:load-test:synthetic-inbound', ['--count' => 1]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing', Artisan::output());
    }
}
