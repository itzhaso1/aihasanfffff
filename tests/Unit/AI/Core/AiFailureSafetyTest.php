<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Contracts\StructuredProviderFailure;
use App\AI\Core\Decision\AiFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiFailureSafetyTest extends TestCase
{
    public function test_structured_provider_failure_exposes_codes_without_messages(): void
    {
        $exception = new class('sk-secret customer +966500000000') extends RuntimeException implements StructuredProviderFailure
        {
            public function providerErrorCode(): string
            {
                return 'http_error';
            }

            public function httpStatus(): ?int
            {
                return 401;
            }
        };

        $failure = AiFailure::fromThrowable(
            AiRuntimeStage::CallModel,
            AiFailureCode::ModelError,
            $exception,
        );

        $payload = $failure->toArray();
        $encoded = json_encode($payload) ?: '';

        $this->assertSame('call_model', $payload['stage']);
        $this->assertSame('model_error', $payload['code']);
        $this->assertSame('http_error', $payload['provider_error_code']);
        $this->assertSame(401, $payload['http_status']);
        $this->assertArrayHasKey('exception_type', $payload);
        $this->assertStringNotContainsString('sk-secret', $encoded);
        $this->assertStringNotContainsString('+9665', $encoded);
    }
}
