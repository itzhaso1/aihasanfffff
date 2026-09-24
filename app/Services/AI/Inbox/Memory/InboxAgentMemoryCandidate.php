<?php

namespace App\Services\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryEvidenceType;
use App\Enums\InboxAgentMemoryScope;
use App\Enums\InboxAgentMemoryValueType;

final class InboxAgentMemoryCandidate
{
    public function __construct(
        public readonly InboxAgentMemoryScope $scope,
        public readonly string $key,
        public readonly mixed $value,
        public readonly InboxAgentMemoryValueType $valueType,
        public readonly float $confidence,
        public readonly InboxAgentMemoryEvidenceType $evidenceType,
        public readonly ?int $sourceMessageId,
        public readonly ?int $sourceConversationId,
        public readonly string $reason = '',
    ) {}
}
