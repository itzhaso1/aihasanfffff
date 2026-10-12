<?php

declare(strict_types=1);

namespace App\AI\Core;

use App\AI\Core\Contracts\EmployeeResolver;
use App\AI\Core\Contracts\ResponseGuard;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\GuardOutcome;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Pipeline\AiStageFailure;
use App\AI\Core\Pipeline\DecisionTrace;
use App\AI\Core\Pipeline\ModelTurnLoop;
use App\AI\Core\Pipeline\PromptAssembler;
use App\AI\Core\Pipeline\TurnResult;
use LogicException;
use Throwable;

/**
 * Orchestrates one run: employee → prompt layers → model/tool loop → guard.
 *
 * It holds no business logic and performs no side effects: it never sends, persists,
 * hands off or falls back to canned text. Any failure yields {@see AiResponse::failed()}.
 */
final class AiRuntime
{
    public function __construct(
        private readonly EmployeeResolver $employees,
        private readonly PromptAssembler $prompts,
        private readonly ModelTurnLoop $turns,
        private readonly ResponseGuard $guard,
    ) {}

    public function handle(AiRequest $request): AiResponse
    {
        $trace = new DecisionTrace($request);

        try {
            return $this->orchestrate($request, $trace);
        } catch (AiStageFailure $failure) {
            return AiResponse::failed($trace->failed($failure->toFailure()));
        } catch (Throwable $exception) {
            return AiResponse::failed($trace->failed(
                AiFailure::fromThrowable(AiRuntimeStage::Runtime, AiFailureCode::Unexpected, $exception),
            ));
        }
    }

    private function orchestrate(AiRequest $request, DecisionTrace $trace): AiResponse
    {
        $employee = AiStageFailure::wrap(
            AiRuntimeStage::ResolveEmployee,
            fn () => $this->employees->resolve($request),
        );
        $trace->withEmployee($employee);

        $prompt = $this->prompts->assemble($request, $employee);
        $trace->withPrompt($prompt);

        if ($prompt->directive() === AiDirective::Skip) {
            return AiResponse::skipped($trace->build(AiResponseStatus::Skipped));
        }
        if ($prompt->directive() === AiDirective::HandOff) {
            return AiResponse::handedOff($trace->build(AiResponseStatus::HandedOff));
        }
        $composition = $prompt->composition
            ?? throw new LogicException('A responding run requires a prompt composition.');

        $turn = $this->turns->run($request, $employee, $composition);
        $trace->withTurn($turn);
        if ($turn->failure !== null) {
            return AiResponse::failed($trace->failed($turn->failure));
        }

        $outcome = $this->review($request, $trace, $turn);
        $trace->withGuard($outcome);
        if ($outcome->verdict === GuardVerdict::Rejected || $outcome->text === null) {
            return AiResponse::failed($trace->failed(new AiFailure(AiRuntimeStage::GuardResponse, AiFailureCode::GuardRejected)));
        }

        return AiResponse::replied($outcome->text, $trace->build(AiResponseStatus::Replied));
    }

    private function review(AiRequest $request, DecisionTrace $trace, TurnResult $turn): GuardOutcome
    {
        return AiStageFailure::wrap(
            AiRuntimeStage::GuardResponse,
            fn (): GuardOutcome => $this->guard->review(new GuardInput(
                request: $request,
                text: (string) $turn->text,
                facts: $trace->facts(),
                toolCalls: $turn->toolCalls,
                confirmedActions: $turn->confirmedActions,
                finishReason: $turn->finishReason,
            )),
        );
    }
}
