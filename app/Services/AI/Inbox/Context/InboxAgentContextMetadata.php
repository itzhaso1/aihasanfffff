<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentContextMetadata
{
    /**
     * @param  array<string, int|bool|string>  $policy
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly int $policyVersion,
        public readonly array $policy,
        public readonly int $historyMessageCount,
        public readonly int $droppedOldestMessageCount,
        public readonly bool $historyTruncated,
    ) {}
}
