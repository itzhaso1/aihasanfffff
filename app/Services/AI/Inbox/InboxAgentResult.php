<?php

namespace App\Services\AI\Inbox;

/**
 * Outcome of an Inbox agent orchestration run.
 */
final class InboxAgentResult
{
    /**
     * @param  array<int, InboxAgentRun>  $runs
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $reply,
        public readonly string $status,
        public readonly int $iterations,
        public readonly ?int $tokensUsed,
        public readonly array $messages,
        public readonly array $metadata,
        public readonly array $raw = [],
        public readonly ?string $failureReason = null,
        public readonly array $runs = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'success' && trim($this->reply) !== '';
    }

    /**
     * @param  array<int, InboxAgentRun>  $runs
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public static function success(
        string $reply,
        int $tokensUsed,
        int $iterations,
        array $messages,
        array $metadata,
        array $raw = [],
        array $runs = [],
    ): self {
        return new self(
            reply: $reply,
            status: 'success',
            iterations: $iterations,
            tokensUsed: $tokensUsed,
            messages: $messages,
            metadata: $metadata,
            raw: $raw,
            runs: $runs,
        );
    }

    /**
     * @param  array<int, InboxAgentRun>  $runs
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(
        string $failureReason,
        int $iterations,
        array $messages,
        array $metadata,
        array $runs = [],
        ?int $tokensUsed = null,
    ): self {
        return new self(
            reply: '',
            status: 'failed',
            iterations: $iterations,
            tokensUsed: $tokensUsed,
            messages: $messages,
            metadata: $metadata,
            failureReason: $failureReason,
            runs: $runs,
        );
    }
}
