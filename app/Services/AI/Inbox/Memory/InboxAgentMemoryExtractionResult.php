<?php

namespace App\Services\AI\Inbox\Memory;

final class InboxAgentMemoryExtractionResult
{
    /**
     * @param  list<InboxAgentMemoryCandidate>  $candidates
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $candidates,
        public readonly int $tokensUsed,
        public readonly array $metadata = [],
    ) {}

    public static function empty(): self
    {
        return new self([], 0, ['empty' => true]);
    }
}
