<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentWorkspaceContextSlice
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $workspaceName,
    ) {}
}
