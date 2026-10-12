<?php

namespace Tests\Feature\Feature\Observability;

use App\Support\Observability\OpsLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpsLoggerTest extends TestCase
{
    public function test_ops_logger_strips_forbidden_keys_and_secrets(): void
    {
        config(['app.key' => 'base64:super-secret-app-key-value']);

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context): bool {
                $this->assertSame('info', $level);
                $this->assertSame('test.event', $message);
                $this->assertArrayNotHasKey('password', $context);
                $this->assertArrayNotHasKey('payload', $context);
                $this->assertArrayNotHasKey('authorization', $context);
                $this->assertSame('safe', $context['status'] ?? null);
                $this->assertStringNotContainsString('super-secret-app-key-value', json_encode($context));

                return true;
            });

        OpsLogger::info('test.event', [
            'status' => 'safe',
            'password' => 'should-not-appear',
            'payload' => ['messages' => ['hello']],
            'authorization' => 'Bearer secret-token',
            'note' => 'contains base64:super-secret-app-key-value substring',
        ]);
    }
}
