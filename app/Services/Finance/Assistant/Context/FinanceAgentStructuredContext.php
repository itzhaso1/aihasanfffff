<?php

namespace App\Services\Finance\Assistant\Context;

use App\Models\Finance\FinanceAiConversation;
use App\Models\User;
use App\Models\Workspace;

/**
 * Bounded, fingerprintable Finance agent context. Independent of Inbox context.
 */
final class FinanceAgentStructuredContext
{
    /**
     * @param  list<string>  $historyRoles
     * @param  list<string>  $metricNames
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $userId,
        public readonly ?int $conversationId,
        public readonly string $locale,
        public readonly ?string $periodFrom,
        public readonly ?string $periodTo,
        public readonly array $metricNames,
        public readonly array $historyRoles,
        public readonly int $historyCount,
        public readonly string $correlationId,
    ) {}

    public static function fromConversation(
        Workspace $workspace,
        User $user,
        ?FinanceAiConversation $conversation,
        string $locale,
        int $historyCount,
        array $historyRoles,
        string $correlationId,
    ): self {
        $metrics = [];
        if ($conversation !== null && is_array($conversation->last_metric_names)) {
            $metrics = array_values(array_slice($conversation->last_metric_names, 0, 12));
        }

        return new self(
            workspaceId: (int) $workspace->id,
            userId: (int) $user->id,
            conversationId: $conversation?->id,
            locale: $locale,
            periodFrom: $conversation?->last_period_from?->toDateString(),
            periodTo: $conversation?->last_period_to?->toDateString(),
            metricNames: $metrics,
            historyRoles: $historyRoles,
            historyCount: $historyCount,
            correlationId: $correlationId,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'locale' => $this->locale,
            'period' => [$this->periodFrom, $this->periodTo],
            'metrics' => $this->metricNames,
            'history_count' => $this->historyCount,
            'history_roles' => $this->historyRoles,
        ], JSON_THROW_ON_ERROR));
    }
}
