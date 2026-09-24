<?php

namespace App\Services\Finance\Assistant;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiUsageAccountant;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Assistant\Conversation\FinanceAgentConversationContext;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationBusyException;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationNotFoundException;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationService;
use Throwable;

final class FinanceAssistantTurnService
{
    public function __construct(
        private readonly FinancialAssistantOrchestrator $orchestrator,
        private readonly FinanceAiConversationService $conversations,
        private readonly FeatureAccessService $featureAccess,
        private readonly AiUsageAccountant $usageAccountant,
    ) {}

    public function assertAiAvailable(User $user, Workspace $workspace): void
    {
        if (! $this->featureAccess->hasFeature($user, $workspace, 'ai')) {
            throw new FinanceAssistantAiUnavailableException;
        }
    }

    /**
     * @return array{
     *     payload: array<string, mixed>,
     *     conversation_id: int
     * }
     */
    public function ask(
        Workspace $workspace,
        User $user,
        string $question,
        ?int $conversationId = null,
        string $locale = 'ar',
    ): array {
        $this->assertAiAvailable($user, $workspace);

        $conversation = $this->conversations->resolveOrCreate($workspace, $user, $conversationId, $locale);
        $turn = $this->conversations->beginTurn($workspace, $user, $conversation, $question, $locale);
        $context = new FinanceAgentConversationContext(
            $turn->conversation,
            $this->conversations->priorMessages($turn),
            $turn->usageEventKey(),
        );

        try {
            $payload = $this->orchestrator->ask(
                $workspace,
                $user,
                $question,
                $locale,
                $context,
                $turn->usageEventKey(),
                consumeUsage: false,
            );

            $payload = $this->conversations->completeTurn(
                $workspace,
                $user,
                $turn,
                $payload,
                $this->orchestrator->lastTrace,
                $this->orchestrator->lastInteractionId,
            );

            if (! ($payload['skip_usage'] ?? false)) {
                $this->commitUsage($workspace, $turn->usageEventKey(), $this->orchestrator->lastTokenTotal);
            }

            return [
                'payload' => $payload,
                'conversation_id' => (int) ($payload['conversation_id'] ?? $conversation->id),
            ];
        } catch (FinanceAiConversationNotFoundException|FinanceAiConversationBusyException $exception) {
            $this->conversations->failTurn($workspace, $user, $turn);
            throw $exception;
        } catch (Throwable $exception) {
            $this->conversations->failTurn($workspace, $user, $turn);
            throw $exception;
        }
    }

    private function commitUsage(Workspace $workspace, string $eventKey, int $tokens): void
    {
        if (! $this->usageAccountant->claim((int) $workspace->id, $eventKey)) {
            return;
        }

        $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
        if ($tokens > 0) {
            $this->featureAccess->consumeUsage($workspace, 'ai_tokens', $tokens, enforce: true);
        }
    }
}
