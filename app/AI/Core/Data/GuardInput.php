<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\AiRequest;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Core\Support\TypedList;

final readonly class GuardInput
{
    /** @var list<FactRecord> */
    public array $facts;

    /** @var list<ToolCallRecord> */
    public array $toolCalls;

    /** @var list<ConfirmedAction> */
    public array $confirmedActions;

    /**
     * @param  list<FactRecord>  $facts
     * @param  list<ToolCallRecord>  $toolCalls
     * @param  list<ConfirmedAction>  $confirmedActions
     */
    public function __construct(
        public AiRequest $request,
        public string $text,
        array $facts = [],
        array $toolCalls = [],
        array $confirmedActions = [],
        public ?ModelFinishReason $finishReason = null,
    ) {
        $this->facts = TypedList::of($facts, FactRecord::class, 'Guard facts');
        $this->toolCalls = TypedList::of($toolCalls, ToolCallRecord::class, 'Guard tool calls');
        $this->confirmedActions = TypedList::of($confirmedActions, ConfirmedAction::class, 'Guard actions');
    }
}
