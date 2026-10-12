<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\AiDecision;
use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\AiReference;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Core\Decision\SafeData;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Core\Decision\TrainingVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AiDecisionTest extends TestCase
{
    public function test_empty_optional_data_serializes_to_stable_defaults(): void
    {
        $decision = new AiDecision(AiResponseStatus::Skipped, AiRunMode::Preview);

        $this->assertSame([
            'schema_version' => AiDecision::SCHEMA_VERSION,
            'status' => 'skipped',
            'run_mode' => 'preview',
            'employee_id' => null,
            'employee_config_version' => null,
            'constitution_version' => null,
            'training_versions' => [],
            'intent' => null,
            'resolved_references' => [],
            'tool_calls' => [],
            'facts' => [],
            'confirmed_actions' => [],
            'guard_results' => [],
            'model_usage' => null,
            'failure' => null,
            'metadata' => [],
        ], $decision->toArray());
    }

    public function test_full_decision_round_trips_through_json_deterministically(): void
    {
        $decision = $this->fullDecision();

        $first = json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $second = json_encode($this->fullDecision(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = json_decode($first, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
        $this->assertSame($decision->toArray(), $decoded);
        $this->assertSame('const-v1', $decoded['constitution_version']);
        $this->assertSame([['id' => 'rules/style', 'version' => 'v2']], $decoded['training_versions']);
        $this->assertSame('cfg-3', $decoded['employee_config_version']);
        $this->assertSame(300, $decoded['model_usage']['total_tokens']);
    }

    public function test_secrets_never_reach_the_serialized_decision(): void
    {
        $decision = new AiDecision(
            status: AiResponseStatus::Replied,
            runMode: AiRunMode::Live,
            toolCalls: [new ToolCallRecord('search_products', 'c1', [
                'query' => 'ايفون',
                'authorization' => 'Bearer live-token',
                'nested' => ['api_key' => 'sk-abcdefghijklmnop1234'],
            ], true)],
            metadata: [
                'api_key' => 'AIzaSyD-very-secret-value-000000',
                'provider_token' => 'raw',
                'note' => 'contains sk-abcdefghijklmnop1234 inline',
                'object' => new \stdClass,
            ],
        );

        $json = json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('AIzaSyD', $json);
        $this->assertStringNotContainsString('sk-abcdefghijklmnop1234', $json);
        $this->assertStringNotContainsString('live-token', $json);
        $this->assertStringNotContainsString('"raw"', $json);
        $this->assertSame(SafeData::REDACTED, $decision->metadata['api_key']);
        $this->assertNull($decision->metadata['object']);
        $this->assertSame('ايفون', $decision->toolCalls[0]->arguments['query']);
    }

    public function test_failure_is_required_exactly_for_failed_decisions(): void
    {
        try {
            new AiDecision(AiResponseStatus::Failed, AiRunMode::Live);
            $this->fail('Failed decision without failure was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new AiDecision(
            AiResponseStatus::Replied,
            AiRunMode::Live,
            failure: new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::ModelError),
        );
    }

    public function test_lists_reject_untyped_items(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiDecision(AiResponseStatus::Replied, AiRunMode::Live, facts: [['source' => 'x']]);
    }

    public function test_failure_keeps_exception_type_but_not_message(): void
    {
        $failure = AiFailure::fromThrowable(
            AiRuntimeStage::CallModel,
            AiFailureCode::ModelError,
            new \RuntimeException('SQLSTATE secret customer +966500000000'),
        );

        $this->assertSame(
            ['stage' => 'call_model', 'code' => 'model_error', 'exception_type' => 'RuntimeException'],
            $failure->toArray(),
        );
    }

    private function fullDecision(): AiDecision
    {
        return new AiDecision(
            status: AiResponseStatus::Replied,
            runMode: AiRunMode::Live,
            employeeId: 7,
            employeeConfigVersion: 'cfg-3',
            constitutionVersion: 'const-v1',
            trainingVersions: [new TrainingVersion('rules/style', 'v2')],
            intent: 'price_question',
            resolvedReferences: [new AiReference('catalog_item', '42')],
            toolCalls: [new ToolCallRecord('search_products', 'c1', ['query' => 'ايفون 15'], true)],
            facts: [new FactRecord('search_products', 'item:42.price', 299)],
            confirmedActions: [new ConfirmedAction('handoff_requested', 'conversation:21')],
            guardResults: [new GuardResult('truth', GuardVerdict::Pass)],
            modelUsage: new ModelUsage('provider-a', 'model-x', 200, 100),
            metadata: ['conversation_id' => 21, 'prompt_section_keys' => ['constitution.core', 'user.message']],
        );
    }
}
