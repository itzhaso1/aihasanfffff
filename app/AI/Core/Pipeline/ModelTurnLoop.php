<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRuntimeLimits;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Contracts\ToolRunner;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\ToolSpec;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Prompt\PromptComposition;
use App\AI\Core\Support\TypedList;

/**
 * Model turn → offered tool calls → next model turn, until final text or a limit.
 * Tools that were not offered to the model are never executed.
 */
final class ModelTurnLoop
{
    public function __construct(
        private readonly ModelGateway $gateway,
        private readonly ToolRunner $tools,
        private readonly AiRuntimeLimits $limits,
    ) {}

    public function run(AiRequest $request, ResolvedEmployee $employee, PromptComposition $prompt): TurnResult
    {
        $state = new TurnState;

        try {
            $offered = AiStageFailure::wrap(
                AiRuntimeStage::ResolveTools,
                fn (): array => TypedList::of($this->tools->availableTools($request, $employee), ToolSpec::class, 'Available tools'),
            );

            for ($round = 0; ; $round++) {
                $response = $this->callModel($request, $employee, $prompt, $offered, $state);
                $failure = $this->terminalFailure($response);
                if ($failure !== null) {
                    return $state->failed($failure);
                }
                if ($response->toolCalls === []) {
                    return $this->finish($response, $state);
                }
                if ($round >= $this->limits->maxToolRounds) {
                    return $state->failed(new AiFailure(AiRuntimeStage::RunTool, AiFailureCode::ToolRoundsExceeded));
                }
                $this->runTools($request, $employee, $offered, $response->toolCalls, $state);
                $state->completeRound();
            }
        } catch (AiStageFailure $failure) {
            return $state->failed($failure->toFailure());
        }
    }

    /**
     * @param  list<ToolSpec>  $offered
     */
    private function callModel(
        AiRequest $request,
        ResolvedEmployee $employee,
        PromptComposition $prompt,
        array $offered,
        TurnState $state,
    ): ModelResponse {
        $response = AiStageFailure::wrap(
            AiRuntimeStage::CallModel,
            fn (): ModelResponse => $this->gateway->generate(
                new ModelRequest($employee->employeeId, $request->runMode, $prompt, $offered, $state->exchanges),
            ),
            AiFailureCode::ModelError,
        );
        $state->recordModelTurn($response);

        return $response;
    }

    private function terminalFailure(ModelResponse $response): ?AiFailure
    {
        return match ($response->finishReason) {
            ModelFinishReason::Error => new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::ModelError),
            ModelFinishReason::Safety => new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::ModelSafetyStop),
            default => null,
        };
    }

    private function finish(ModelResponse $response, TurnState $state): TurnResult
    {
        $text = trim((string) $response->text);

        return $text === ''
            ? $state->failed(new AiFailure(AiRuntimeStage::CallModel, AiFailureCode::EmptyModelResponse))
            : $state->completed($text);
    }

    /**
     * @param  list<ToolSpec>  $offered
     * @param  list<ModelToolCall>  $calls
     */
    private function runTools(
        AiRequest $request,
        ResolvedEmployee $employee,
        array $offered,
        array $calls,
        TurnState $state,
    ): void {
        $offeredNames = array_map(static fn (ToolSpec $tool): string => $tool->name, $offered);

        foreach ($calls as $call) {
            if (! in_array($call->name, $offeredNames, true)) {
                $state->recordNotOffered($call);

                continue;
            }
            $outcome = AiStageFailure::wrap(
                AiRuntimeStage::RunTool,
                fn () => $this->tools->run($request, $employee, $call),
            );
            $state->recordOutcome($call, $outcome);
        }
    }
}
