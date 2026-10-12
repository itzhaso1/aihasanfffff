<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

final readonly class ToolExchange
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public ModelToolCall $call,
        public array $result,
    ) {}
}
