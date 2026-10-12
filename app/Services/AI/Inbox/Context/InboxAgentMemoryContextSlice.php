<?php

namespace App\Services\AI\Inbox\Context;

use App\Services\AI\Inbox\Memory\InboxAgentRetrievedMemory;

final class InboxAgentMemoryContextSlice
{
    /**
     * @param  list<InboxAgentRetrievedMemory>  $customerMemories
     * @param  list<InboxAgentRetrievedMemory>  $conversationMemories
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $customerMemories,
        public readonly array $conversationMemories,
        public readonly string $retrievalFingerprint,
    ) {}
}
