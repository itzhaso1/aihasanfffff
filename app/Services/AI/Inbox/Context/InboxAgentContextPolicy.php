<?php

namespace App\Services\AI\Inbox\Context;

/**
 * Deterministic context window and budget policy for the Inbox agent.
 */
final class InboxAgentContextPolicy
{
    public const VERSION = 1;

    public function maxRecentMessages(): int
    {
        return max(0, (int) config('ai.inbox_agent.context.max_messages', 8));
    }

    public function maxMessageCharacters(): int
    {
        return max(80, (int) config('ai.inbox_agent.context.max_message_chars', 400));
    }

    public function maxHistoryCharacters(): int
    {
        return max(200, (int) config('ai.inbox_agent.context.max_history_chars', 4000));
    }

    public function maxBusinessCharacters(): int
    {
        return max(200, (int) config('ai.inbox_agent.context.max_business_chars', 3500));
    }

    public function maxSystemPromptCharacters(): int
    {
        return max(1000, (int) config('ai.inbox_agent.context.max_system_chars', 16000));
    }

    /**
     * @return array<string, int|bool>
     */
    public function toMetadata(): array
    {
        return [
            'context_policy_version' => self::VERSION,
            'context_max_messages' => $this->maxRecentMessages(),
            'context_max_message_chars' => $this->maxMessageCharacters(),
            'context_max_history_chars' => $this->maxHistoryCharacters(),
        ];
    }
}
