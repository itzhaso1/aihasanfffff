<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ToolExchange;
use App\AI\Core\Data\ToolOutcome;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Core\Decision\ToolCallRecord;

/**
 * Per-run accumulator for {@see ModelTurnLoop}. One instance lives for one run only.
 */
final class TurnState
{
    /** @var list<ToolExchange> */
    public array $exchanges = [];

    /** @var list<ToolCallRecord> */
    private array $toolCalls = [];

    /** @var list<FactRecord> */
    private array $facts = [];

    /** @var list<ConfirmedAction> */
    private array $confirmedActions = [];

    private ?ModelUsage $usage = null;

    private ?ModelFinishReason $finishReason = null;

    private int $toolRounds = 0;

    public function recordModelTurn(ModelResponse $response): void
    {
        $this->finishReason = $response->finishReason;
        if ($response->usage !== null) {
            $this->usage = $this->usage?->plus($response->usage) ?? $response->usage;
        }
    }

    public function recordOutcome(ModelToolCall $call, ToolOutcome $outcome): void
    {
        $this->toolCalls[] = $outcome->record;
        array_push($this->facts, ...$outcome->facts);
        if ($outcome->confirmedAction !== null) {
            $this->confirmedActions[] = $outcome->confirmedAction;
        }
        $this->exchanges[] = new ToolExchange($call, $outcome->modelPayload);
    }

    public function recordNotOffered(ModelToolCall $call): void
    {
        $this->toolCalls[] = new ToolCallRecord($call->name, $call->callId, $call->arguments, false, ToolCallRecord::NOT_OFFERED);
        $this->exchanges[] = new ToolExchange($call, ['error' => ToolCallRecord::NOT_OFFERED]);
    }

    public function completeRound(): void
    {
        $this->toolRounds++;
    }

    public function completed(string $text): TurnResult
    {
        return $this->result($text, null);
    }

    public function failed(AiFailure $failure): TurnResult
    {
        return $this->result(null, $failure);
    }

    private function result(?string $text, ?AiFailure $failure): TurnResult
    {
        return new TurnResult(
            text: $text,
            toolCalls: $this->toolCalls,
            facts: $this->facts,
            confirmedActions: $this->confirmedActions,
            usage: $this->usage,
            finishReason: $this->finishReason,
            toolRounds: $this->toolRounds,
            failure: $failure,
        );
    }
}
