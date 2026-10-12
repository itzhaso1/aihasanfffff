<?php

namespace App\Services\AI\Inbox\Tools;

/**
 * Provider-neutral structured tool call.
 */
final class InboxAgentToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly string $toolName,
        public readonly string $callId,
        public readonly array $arguments,
        public readonly array $providerMetadata = [],
    ) {}
}
