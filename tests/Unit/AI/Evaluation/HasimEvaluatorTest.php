<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Evaluation;

use App\AI\Core\AiDecision;
use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\GuardResult;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Core\Decision\TrainingVersion;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationExpectations;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;
use App\AI\Evaluation\EvaluationStatus;
use App\AI\Evaluation\HasimEvaluator;
use App\AI\Tools\CanonicalTools;
use PHPUnit\Framework\TestCase;

class HasimEvaluatorTest extends TestCase
{
    public function test_clean_grounded_reply_passes_with_versioned_score(): void
    {
        $result = (new HasimEvaluator)->evaluate($this->request(
            'الفستان الأسود سعره 10.00 ريال',
            $this->decision(facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)]),
        ));
        $again = (new HasimEvaluator)->evaluate($this->request(
            'الفستان الأسود سعره 10.00 ريال',
            $this->decision(facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)]),
        ));

        $this->assertSame(EvaluationStatus::Passed, $result->status);
        $this->assertSame(100, $result->score);
        $this->assertSame([], $result->findings);
        $this->assertSame(HasimEvaluator::EVALUATOR, $result->evaluator);
        $this->assertSame(HasimEvaluator::VERSION, $result->version);
        $this->assertSame($result->toArray(), $again->toArray());
        $this->assertSame('0 fail, 0 warn', $result->summary);
    }

    public function test_warning_does_not_fail_the_result(): void
    {
        $result = (new HasimEvaluator)->evaluate($this->request('مَرْحَبًا', $this->decision()));

        $this->assertSame(EvaluationStatus::PassedWithWarnings, $result->status);
        $this->assertSame(95, $result->score);
        $this->assertSame(EvaluationSeverity::Warn, $result->findings[0]->severity);
        $this->assertSame('style_normalized', $result->findings[0]->code);
    }

    public function test_hard_failure_and_unsupported_price_are_detected(): void
    {
        $invented = (new HasimEvaluator)->evaluate($this->request('سعره 299 ريال', $this->decision()));
        $conflict = (new HasimEvaluator)->evaluate($this->request(
            'سعره 299 ريال',
            $this->decision(facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)]),
        ));
        $availability = (new HasimEvaluator)->evaluate($this->request(
            'المنتج متوفر الحين',
            $this->decision(facts: [new FactRecord('search_products', 'catalog_item:12.availability', 'out_of_stock')]),
        ));

        $this->assertSame(EvaluationStatus::Failed, $invented->status);
        $this->assertSame(75, $invented->score);
        $this->assertSame('invented_price', $invented->findings[0]->code);
        $this->assertSame(EvaluationCategory::Truth, $invented->findings[0]->category);
        $this->assertSame('ungrounded_price', $conflict->findings[0]->code);
        $this->assertSame('availability_contradiction', $availability->findings[0]->code);
    }

    public function test_unsupported_action_claim_and_security_leak_fail(): void
    {
        $action = (new HasimEvaluator)->evaluate($this->request('حفظت القياس عندي', $this->decision()));
        $allowed = (new HasimEvaluator)->evaluate($this->request(
            'حفظت القياس عندي',
            $this->decision(actions: [new ConfirmedAction(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, '4')]),
        ));
        $secret = (new HasimEvaluator)->evaluate($this->request('token sk-abcdefghijklmnopqrstuv', $this->decision()));

        $this->assertSame(EvaluationStatus::Failed, $action->status);
        $this->assertSame('unconfirmed_action', $action->findings[0]->code);
        $this->assertSame(EvaluationStatus::Passed, $allowed->status);
        $this->assertSame('secret_leak', $secret->findings[0]->code);
        $this->assertSame(EvaluationCategory::Security, $secret->findings[0]->category);
    }

    public function test_tool_result_mismatch_and_not_offered_are_detected(): void
    {
        $mismatch = (new HasimEvaluator)->evaluate($this->request(
            'المنتج متوفر',
            $this->decision(tools: [new ToolCallRecord('search_products', 'c1', ['query' => 'فستان'], false, 'search_failed')]),
        ));
        $unoffered = (new HasimEvaluator)->evaluate($this->request(
            'هلا',
            $this->decision(tools: [new ToolCallRecord('search_products', 'c1', [], false, ToolCallRecord::NOT_OFFERED)]),
        ));

        $this->assertSame('tool_result_mismatch', $mismatch->findings[0]->code);
        $this->assertSame(EvaluationSeverity::Fail, $mismatch->findings[0]->severity);
        $this->assertSame('tool_not_offered', $unoffered->findings[0]->code);
    }

    public function test_persona_and_training_mismatch_are_warnings_only_when_expected(): void
    {
        $plain = (new HasimEvaluator)->evaluate($this->request(
            'The black dress is available in our store today',
            $this->decision(),
        ));
        $persona = (new HasimEvaluator)->evaluate(new EvaluationRequest(
            decision: $this->decision(),
            text: 'The black dress is available in our store today',
            expectations: new EvaluationExpectations(language: 'ar', dialect: 'saudi', dialectHint: 'saudi'),
        ));
        $training = (new HasimEvaluator)->evaluate(new EvaluationRequest(
            decision: $this->decision(training: [new TrainingVersion('rules/sales', '1')]),
            text: 'هلا',
            expectations: new EvaluationExpectations(requiredTrainingIds: ['rules/communication']),
        ));

        $this->assertSame(EvaluationStatus::Passed, $plain->status);
        $this->assertSame(EvaluationStatus::PassedWithWarnings, $persona->status);
        $this->assertSame('persona_language_mismatch', $persona->findings[0]->code);
        $this->assertSame(EvaluationSeverity::Warn, $training->findings[0]->severity);
        $this->assertSame('training_mismatch', $training->findings[0]->code);
        $this->assertNotSame(EvaluationStatus::Failed, $training->status);
    }

    public function test_empty_and_incomplete_responses_are_detected(): void
    {
        $empty = (new HasimEvaluator)->evaluate($this->request('  ', $this->decision()));
        $incomplete = (new HasimEvaluator)->evaluate(new EvaluationRequest(
            decision: $this->decision(facts: [new FactRecord('search_products', 'catalog_item:12.price', 10)]),
            text: 'سعره 10 ريال',
            finishReason: ModelFinishReason::Length,
        ));
        $failed = (new HasimEvaluator)->evaluate(new EvaluationRequest(
            decision: $this->decision(
                status: AiResponseStatus::Failed,
                failure: new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::ModelError),
            ),
        ));

        $this->assertSame(EvaluationStatus::Failed, $empty->status);
        $this->assertSame('empty_output', $empty->findings[0]->code);
        $this->assertSame(EvaluationStatus::PassedWithWarnings, $incomplete->status);
        $this->assertSame('incomplete_response', $incomplete->findings[0]->code);
        $this->assertSame('model_error', $failed->findings[0]->code);
        $this->assertSame(75, $failed->score);
    }

    public function test_existing_guard_rejects_are_structured_signals_and_not_duplicated(): void
    {
        $result = (new HasimEvaluator)->evaluate($this->request(
            'سعره 299 ريال',
            $this->decision(guards: [
                new GuardResult('truth', GuardVerdict::Rejected, 'invented_price'),
            ]),
        ));

        $this->assertCount(1, $result->findings);
        $this->assertSame('guard_signal', $result->findings[0]->rule);
        $this->assertSame('invented_price', $result->findings[0]->code);
        $this->assertSame(EvaluationStatus::Failed, $result->status);
    }

    /**
     * @param  list<FactRecord>  $facts
     * @param  list<ToolCallRecord>  $tools
     * @param  list<ConfirmedAction>  $actions
     * @param  list<GuardResult>  $guards
     * @param  list<TrainingVersion>  $training
     */
    private function decision(
        AiResponseStatus $status = AiResponseStatus::Replied,
        array $facts = [],
        array $tools = [],
        array $actions = [],
        array $guards = [],
        array $training = [],
        ?AiFailure $failure = null,
    ): AiDecision {
        return new AiDecision(
            status: $status,
            runMode: AiRunMode::Preview,
            facts: $facts,
            toolCalls: $tools,
            confirmedActions: $actions,
            guardResults: $guards,
            trainingVersions: $training,
            failure: $failure,
        );
    }

    private function request(string $text, AiDecision $decision): EvaluationRequest
    {
        return new EvaluationRequest($decision, $text);
    }
}
