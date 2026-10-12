<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Core\Decision\ToolCallRecord;

/**
 * Outcome of the model/tool loop. Records gathered before a failure are kept so the
 * decision trace shows what already happened.
 */
final readonly class TurnResult
{
    /**
     * @param  list<ToolCallRecord>  $toolCalls
     * @param  list<FactRecord>  $facts
     * @param  list<ConfirmedAction>  $confirmedActions
     */
    public function __construct(
        public ?string $text,
        public array $toolCalls,
        public array $facts,
        public array $confirmedActions,
        public ?ModelUsage $usage,
        public ?ModelFinishReason $finishReason,
        public int $toolRounds,
        public ?AiFailure $failure = null,
    ) {}
}
