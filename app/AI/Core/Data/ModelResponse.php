<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\Decision\ModelUsage;
use App\AI\Core\Support\TypedList;

final readonly class ModelResponse
{
    /** @var list<ModelToolCall> */
    public array $toolCalls;

    /**
     * @param  list<ModelToolCall>  $toolCalls
     */
    public function __construct(
        public ?string $text,
        array $toolCalls = [],
        public ModelFinishReason $finishReason = ModelFinishReason::Stop,
        public ?ModelUsage $usage = null,
    ) {
        $this->toolCalls = TypedList::of($toolCalls, ModelToolCall::class, 'Model tool calls');
    }
}
