<?php

namespace App\Services\AI\Inbox\Memory;

use App\Models\AiSetting;
use App\Support\Tenancy\WorkspaceContext;

final class InboxAgentMemoryPolicy
{
    public function enabled(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if ($workspace !== null) {
            $raw = AiSetting::query()->where('workspace_id', $workspace->id)->value('rules');
            $rules = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($rules) && array_key_exists('memory_enabled', $rules)) {
                return (bool) $rules['memory_enabled'];
            }
        }

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
