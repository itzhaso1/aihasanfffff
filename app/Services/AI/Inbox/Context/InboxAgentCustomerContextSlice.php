<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentCustomerContextSlice
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $displayName,
        public readonly string $phone,
        public readonly ?string $channelIdentifier,
    ) {}
}
