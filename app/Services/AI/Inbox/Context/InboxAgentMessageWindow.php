<?php

namespace App\Services\AI\Inbox\Context;

/**
 * Recent history plus the current inbound message (never duplicated in history).
 */
final class InboxAgentMessageWindow
{
    /**
     * @param  list<InboxAgentNormalizedMessage>  $history  Chronological, excludes current message.
     */
    public function __construct(
        public readonly array $history,
        public readonly InboxAgentNormalizedMessage $currentMessage,
        public readonly int $droppedOldestCount,
        public readonly bool $perMessageTruncated,
        public readonly bool $historyBudgetTruncated,
    ) {}
}
