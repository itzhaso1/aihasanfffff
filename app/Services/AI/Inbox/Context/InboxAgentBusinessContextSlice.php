<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentBusinessContextSlice
{
    /**
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, mixed>|null  $businessInformation
     */
    public function __construct(
        public readonly string $aiRole,
        public readonly string $instructions,
        public readonly string $tone,
        public readonly string $replyStyle,
        public readonly ?array $rules,
        public readonly ?array $businessInformation,
    ) {}
}
