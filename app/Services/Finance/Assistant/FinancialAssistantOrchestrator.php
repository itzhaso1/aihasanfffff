<?php

namespace App\Services\Finance\Assistant;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\Assistant\Agent\FinanceAgentOrchestrator;
use App\Services\Finance\Assistant\Conversation\ConversationEntityState;
use App\Services\Finance\Assistant\Conversation\ConversationEntityTracker;
use App\Services\Finance\Assistant\Conversation\FinanceAgentConversationContext;
use App\Services\Finance\Assistant\Handlers\FinancialAssistantAmbiguityHandler;
use App\Services\Finance\Assistant\Security\FinanceAgentRunState;
use App\Services\Finance\Assistant\Security\FinanceAgentSecurityContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Unified entry point for the HASEM Financial Assistant.
 *
 * All clients (Flutter, Web, future surfaces) should route through this orchestrator.
 */
final class FinancialAssistantOrchestrator
{
    /** @var list<array<string, mixed>> */
    public array $lastTrace = [];

    public ?string $lastInteractionId = null;

    public int $lastTokenTotal = 0;

    public function __construct(
        private readonly FinancialAssistantIntentRouter $intentRouter,
        private readonly FinanceAssistantOrchestrator $plannerOrchestrator,
        private readonly FinanceAgentOrchestrator $agentOrchestrator,
        private readonly ConversationEntityTracker $entityTracker,
        private readonly FinancialAssistantAmbiguityHandler $ambiguityHandler,
        private readonly AuditLogService $auditLogService,
        private readonly FinanceAgentRunState $runState,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ask(
        Workspace $workspace,
        User $user,
        string $question,
        string $locale = 'ar',
        ?FinanceAgentConversationContext $conversationContext = null,
        ?string $usageEventKey = null,
        bool $consumeUsage = true,
    ): array {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('Question is required.');
        }

        $this->runState->begin(FinanceAgentSecurityContext::fromActors(
            $workspace,
            $user,
            $conversationContext?->conversation->id,
            'fai_'.Str::uuid()->toString(),
        ));

        $intent = $this->intentRouter->resolve($question);
        if ($intent !== null) {
            return $this->intentResponse($workspace, $user, $question, $locale, $intent);
        }

        $entityContext = $this->resolveEntityContext($conversationContext, $question);

        $ambiguity = $entityContext !== null
            ? $this->ambiguityHandler->match($workspace, $question, $entityContext)
            : null;
        if ($ambiguity !== null) {
            return $this->intentResponse($workspace, $user, $question, $locale, $ambiguity);
        }

        if ((bool) config('finance_assistant.agent_enabled')) {
            $payload = $this->agentOrchestrator->ask(
                $workspace,
                $user,
                $question,
                $locale,
                $conversationContext,
                $usageEventKey,
                $consumeUsage,
            );
            $this->lastTrace = $this->agentOrchestrator->lastTrace;
            $this->lastInteractionId = $this->agentOrchestrator->lastInteractionId;
            $this->lastTokenTotal = $this->agentOrchestrator->lastTokenTotal;

            return $payload;
        }

        $this->lastTrace = [];
        $this->lastInteractionId = null;
        $this->lastTokenTotal = 0;

        return $this->plannerOrchestrator->ask(
            $workspace,
            $user,
            $question,
            $locale,
            $usageEventKey,
            $consumeUsage,
            $entityContext,
        );
    }

    /**
     * @param  array{
     *     intent: string,
     *     answer_ar: string,
     *     answer_en: string,
     *     reason?: string,
     *     variant?: string
     * }  $intent
     * @return array<string, mixed>
     */
    private function resolveEntityContext(?FinanceAgentConversationContext $conversationContext, string $question): ?ConversationEntityState
    {
        if ($conversationContext === null) {
            return null;
        }

        $state = $this->entityTracker->fromConversation($conversationContext->conversation);

        return $this->entityTracker->mergeFromQuestion($state, $question);
    }

    private function intentResponse(
        Workspace $workspace,
        User $user,
        string $question,
        string $locale,
        array $intent,
    ): array {
        $started = microtime(true);
        $answer = $locale === 'ar' ? $intent['answer_ar'] : $intent['answer_en'];
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        Log::info('finance.assistant.ask', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'tools' => [],
            'intent' => $intent['intent'],
            'duration_ms' => $durationMs,
            'success' => true,
        ]);

        $this->auditLogService->log(
            action: 'finance.assistant.ask',
            entityType: Workspace::class,
            entityId: (int) $workspace->id,
            actor: $user,
            meta: [
                'tools' => [],
                'intent' => $intent['intent'],
                'duration_ms' => $durationMs,
                'question_length' => mb_strlen($question),
            ],
            workspaceId: (int) $workspace->id,
        );

        return [
            'answer' => $answer,
            'facts' => [],
            'tools_used' => [],
            'period' => null,
            'intent' => $intent['intent'],
            'skip_usage' => true,
        ];
    }
}
