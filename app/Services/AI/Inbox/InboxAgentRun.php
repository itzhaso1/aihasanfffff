<?php

namespace App\Services\AI\Inbox;

/**
 * Snapshot of a single agent loop iteration.
 */
final class InboxAgentRun
{
    /**
     * @param  array<string, mixed>|null  $providerRaw
     */
    public function __construct(
        public readonly int $iteration,
        public readonly string $status,
        public readonly ?string $content = null,
        public readonly int $tokensUsed = 0,
        public readonly ?array $providerRaw = null,
        public readonly ?string $failureReason = null,
        public readonly int $toolCallsCount = 0,
        public readonly int $toolRound = 0,
    ) {}
}
