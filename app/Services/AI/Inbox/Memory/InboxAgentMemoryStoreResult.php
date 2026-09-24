<?php

namespace App\Services\AI\Inbox\Memory;

use App\Models\InboxAgentMemory;

final class InboxAgentMemoryStoreResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly InboxAgentMemory $memory,
        public readonly ?InboxAgentMemory $superseded = null,
    ) {}

    public static function created(InboxAgentMemory $memory, ?InboxAgentMemory $superseded): self
    {
        return new self('created', $memory, $superseded);
    }

    public static function deduplicated(InboxAgentMemory $memory): self
    {
        return new self('deduplicated', $memory);
    }
}
