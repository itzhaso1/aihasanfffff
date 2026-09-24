<?php

namespace App\Services\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryScope;

final class InboxAgentRetrievedMemory
{
    public function __construct(
        public readonly InboxAgentMemoryScope $scope,
        public readonly string $key,
        public readonly string $displayValue,
    ) {}
}
