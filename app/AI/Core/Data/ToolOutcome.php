<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Core\Support\TypedList;

final readonly class ToolOutcome
{
    /** @var list<FactRecord> */
    public array $facts;

    /**
     * @param  array<string, mixed>  $modelPayload  Result handed back to the model on the next turn.
     * @param  list<FactRecord>  $facts
     */
    public function __construct(
        public ToolCallRecord $record,
        public array $modelPayload,
        array $facts = [],
        public ?ConfirmedAction $confirmedAction = null,
    ) {
        $this->facts = TypedList::of($facts, FactRecord::class, 'Tool facts');
    }
}
