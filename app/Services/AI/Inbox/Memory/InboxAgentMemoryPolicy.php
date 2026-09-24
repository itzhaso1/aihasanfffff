<?php

namespace App\Services\AI\Inbox\Memory;

final class InboxAgentMemoryPolicy
{
    public function enabled(): bool
    {
        return (bool) config('ai.inbox_memory.enabled', true);
    }

    public function minConfidence(): float
    {
        return max(0.0, min(1.0, (float) config('ai.inbox_memory.min_confidence', 0.85)));
    }

    public function maxCandidates(): int
    {
        return max(1, (int) config('ai.inbox_memory.max_candidates', 5));
    }

    public function maxRetrieved(): int
    {
        return max(1, (int) config('ai.inbox_memory.max_retrieved', 8));
    }

    public function maxValueChars(): int
    {
        return max(32, (int) config('ai.inbox_memory.max_value_chars', 500));
    }

    public function conversationTtlDays(): int
    {
        return max(1, (int) config('ai.inbox_memory.conversation_ttl_days', 30));
    }

    public function allowInferred(): bool
    {
        return (bool) config('ai.inbox_memory.allow_inferred', false);
    }
}
