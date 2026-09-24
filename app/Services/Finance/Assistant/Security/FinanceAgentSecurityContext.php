<?php

namespace App\Services\Finance\Assistant\Security;

use App\Models\User;
use App\Models\Workspace;

/**
 * Trusted Finance agent identity. Never populated from model output.
 */
final class FinanceAgentSecurityContext
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $userId,
        public readonly ?int $conversationId,
        public readonly string $correlationId,
    ) {}

    public static function fromActors(
        Workspace $workspace,
        User $user,
        ?int $conversationId,
        string $correlationId,
    ): self {
        return new self(
            workspaceId: (int) $workspace->id,
            userId: (int) $user->id,
            conversationId: $conversationId,
            correlationId: $correlationId,
        );
    }
}
