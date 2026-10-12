<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Employees;

use App\AI\Core\AiDecision;
use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponse;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Employees\Preview\EmployeePreviewResult;
use App\AI\Models\ResolvedModelSettings;
use PHPUnit\Framework\TestCase;

class EmployeePreviewResultTest extends TestCase
{
    public function test_successful_reply_is_not_a_hard_failure_when_latency_is_high(): void
    {
        $this->assertSame(EmployeePreviewResult::OUTCOME_SUCCESS, EmployeePreviewResult::classify(true, 4592));
        $this->assertSame(
            EmployeePreviewResult::OUTCOME_LATENCY_WARNING,
            EmployeePreviewResult::classify(true, EmployeePreviewResult::LATENCY_WARNING_MS),
        );
        $this->assertSame(EmployeePreviewResult::OUTCOME_FAILED, EmployeePreviewResult::classify(false, 4592));
    }

    public function test_payload_uses_resolved_settings_and_keeps_latency_as_warning(): void
    {
        $settings = new ResolvedModelSettings(7, 3, 'openai', 'gpt-4o-mini', 0.2, 256);
        $response = AiResponse::replied('أهلا', $this->decision(AiResponseStatus::Replied, new ModelUsage('openai', 'gpt-4o-mini', 4, 6)));

        $payload = EmployeePreviewResult::from(
            $response,
            $settings,
            'platform',
            EmployeePreviewResult::LATENCY_WARNING_MS + 12,
        )->toArray();

        $this->assertSame(EmployeePreviewResult::OUTCOME_LATENCY_WARNING, $payload['outcome']);
        $this->assertSame('openai', $payload['provider']);
        $this->assertSame('gpt-4o-mini', $payload['model']);
        $this->assertSame('gpt-4o-mini', $payload['used_model']);
        $this->assertSame(7, $payload['employee_id']);
        $this->assertFalse($payload['deliverable']);
        $this->assertTrue($payload['checks'][2]['ok']);
        $this->assertTrue($payload['checks'][2]['warning']);
        $this->assertSame(10, $payload['tokens']);
    }

    public function test_payload_reports_resolver_settings_not_usage_echo(): void
    {
        $settings = new ResolvedModelSettings(7, 3, 'openai', 'gpt-4o-mini', 0.2, 256);
        $response = AiResponse::replied('أهلا', $this->decision(
            AiResponseStatus::Replied,
            new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 4, 6),
        ));

        $payload = EmployeePreviewResult::from($response, $settings, 'platform', 80)->toArray();

        $this->assertSame('openai', $payload['provider']);
        $this->assertSame('gpt-4o-mini', $payload['model']);
        $this->assertSame('gpt-4o-mini', $payload['used_model']);
        $this->assertSame('platform', $payload['settings_source']);
        $this->assertSame(10, $payload['tokens']);
    }

    public function test_model_error_is_a_structured_failure(): void
    {
        $settings = new ResolvedModelSettings(7, 3, 'openai', 'gpt-4o-mini', 0.2, 256);
        $response = AiResponse::failed($this->decision(
            AiResponseStatus::Failed,
            null,
            new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::ModelError, 'ModelGatewayException'),
        ));

        $payload = EmployeePreviewResult::from($response, $settings, 'platform', 120)->toArray();

        $this->assertSame(EmployeePreviewResult::OUTCOME_FAILED, $payload['outcome']);
        $this->assertFalse($payload['checks'][0]['ok']);
        $this->assertSame('model_error', $payload['failure']['code']);
        $this->assertStringContainsString('النموذج', (string) $payload['error']);
        $this->assertStringNotContainsString('sk-', (string) $payload['error']);
    }

    private function decision(
        AiResponseStatus $status,
        ?ModelUsage $usage = null,
        ?AiFailure $failure = null,
    ): AiDecision {
        return new AiDecision(
            status: $status,
            runMode: AiRunMode::Preview,
            employeeId: 7,
            constitutionVersion: 'test',
            modelUsage: $usage,
            failure: $failure,
        );
    }
}
