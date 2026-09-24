<?php

namespace App\Services\Finance\Assistant;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiUsageAccountant;
use App\Services\Audit\AuditLogService;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Assistant\Conversation\ConversationEntityState;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class FinanceAssistantOrchestrator
{
    public function __construct(
        private readonly FinanceAssistantLlmPlanner $planner,
        private readonly FinanceAssistantHeuristicPlanner $heuristicPlanner,
        private readonly FinanceAssistantToolExecutor $executor,
        private readonly FinanceAssistantAnswerComposer $answerComposer,
        private readonly FinanceAssistantQuestionSemantics $semantics,
        private readonly FeatureAccessService $featureAccess,
        private readonly AiUsageAccountant $usageAccountant,
        private readonly AuditLogService $auditLogService,
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinancialAssistantMetricRegistry $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ask(
        Workspace $workspace,
        User $user,
        string $question,
        string $locale = 'ar',
        ?string $usageEventKey = null,
        bool $consumeUsage = true,
        ?ConversationEntityState $entityContext = null,
    ): array {
        $started = microtime(true);
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('Question is required.');
        }

        $this->featureAccess->assertWithinLimit($workspace, 'ai_usage', 1);
        $this->featureAccess->assertWithinLimit($workspace, 'ai_tokens', 256);

        $semanticBlock = $this->semantics->evaluate($question);
        if ($semanticBlock !== null) {
            return $this->semanticBlockResponse($workspace, $user, $question, $locale, $semanticBlock, $started, $usageEventKey, $consumeUsage);
        }

        $plans = $this->shouldUseHeuristicPlanner()
            ? $this->heuristicPlanner->plan($question, $entityContext)
            : $this->planner->plan($workspace, $question, $locale, $entityContext);

        return $this->askWithPlans($workspace, $user, $question, $plans, $locale, $started, $usageEventKey, $consumeUsage);
    }

    /**
     * Execute a pre-resolved tool plan. Used by ask() and automated tests.
     *
     * @param  list<array{name: string, arguments?: array<string, mixed>}>  $plans
     * @return array<string, mixed>
     */
    public function askWithPlans(
        Workspace $workspace,
        User $user,
        string $question,
        array $plans,
        string $locale = 'ar',
        ?float $started = null,
        ?string $usageEventKey = null,
        bool $consumeUsage = true,
    ): array {
        $started ??= microtime(true);

        $maxTools = max(1, (int) config('finance_assistant.max_tools_per_request', 5));
        if (count($plans) > $maxTools) {
            throw new InvalidArgumentException('Too many tools requested for one question.');
        }

        $results = $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $plans): array {
            $executed = [];
            foreach ($plans as $plan) {
                $executed[] = $this->executor->execute($workspace, $user, $plan);
            }

            return $executed;
        });

        if ($plans === []) {
            $answer = $locale === 'ar'
                ? 'لا أستطيع الإجابة من السجلات المتاحة. جرّب سؤالًا عن المبيعات، المصاريف، الربح، الرواتب، الموردين، المخزون، أو ملخص الوضع المالي.'
                : 'I cannot answer from available records. Try asking about sales, expenses, profit, payroll, payables, inventory, or a financial summary.';
        } else {
            $answer = $this->answerComposer->compose($workspace, $question, $results, $locale);
        }

        $eventKey = $usageEventKey ?: 'finance-assistant:'.sha1($workspace->id.'|'.$user->id.'|'.$question.'|'.now()->toDateString());
        if ($consumeUsage && $this->usageAccountant->claim((int) $workspace->id, $eventKey)) {
            $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
        }

        $facts = $this->factsFromResults($results);
        $period = $this->resolveOverallPeriod($facts);
        $toolsUsed = array_values(array_unique(array_map(fn (FinanceAssistantToolResult $r): string => $r->tool, $results)));

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        Log::info('finance.assistant.ask', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'tools' => $toolsUsed,
            'duration_ms' => $durationMs,
            'success' => true,
        ]);

        $this->auditLogService->log(
            action: 'finance.assistant.ask',
            entityType: Workspace::class,
            entityId: (int) $workspace->id,
            actor: $user,
            meta: [
                'tools' => $toolsUsed,
                'duration_ms' => $durationMs,
                'question_length' => mb_strlen($question),
            ],
            workspaceId: (int) $workspace->id,
        );

        return [
            'answer' => $answer,
            'facts' => $facts,
            'tools_used' => $toolsUsed,
            'period' => $period,
        ];
    }

    /**
     * @param  array{type: string, tool: string, reason: string, requested_period?: array{from: string, to: string, label: string}, answer_ar: string, answer_en: string}  $block
     * @return array<string, mixed>
     */
    private function semanticBlockResponse(
        Workspace $workspace,
        User $user,
        string $question,
        string $locale,
        array $block,
        float $started,
        ?string $usageEventKey = null,
        bool $consumeUsage = true,
    ): array {
        $extra = [];
        if (isset($block['requested_period'])) {
            $extra['requested_period'] = $block['requested_period'];
        }

        $result = FinanceAssistantToolResult::unavailable(
            $block['tool'],
            $block['reason'],
            $extra,
        );

        $answer = $locale === 'ar' ? $block['answer_ar'] : $block['answer_en'];
        $facts = [$result->toFactArray()];
        $period = isset($block['requested_period'])
            ? [
                'from' => $block['requested_period']['from'],
                'to' => $block['requested_period']['to'],
            ]
            : null;

        $eventKey = $usageEventKey ?: 'finance-assistant:'.sha1($workspace->id.'|'.$user->id.'|'.$question.'|'.now()->toDateString());
        if ($consumeUsage && $this->usageAccountant->claim((int) $workspace->id, $eventKey)) {
            $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        Log::info('finance.assistant.ask', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'tools' => [],
            'semantic_block' => $block['reason'],
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
                'semantic_block' => $block['reason'],
                'duration_ms' => $durationMs,
                'question_length' => mb_strlen($question),
            ],
            workspaceId: (int) $workspace->id,
        );

        return [
            'answer' => $answer,
            'facts' => $facts,
            'tools_used' => [],
            'period' => $period,
        ];
    }

    /**
     * @param  list<FinanceAssistantToolResult>  $results
     * @return list<array<string, mixed>>
     */
    private function factsFromResults(array $results): array
    {
        return array_map(function (FinanceAssistantToolResult $result): array {
            $fact = $result->toFactArray();
            $fact['data'] = $this->metrics->enrichFactData($fact['data']);

            return $fact;
        }, $results);
    }

    private function shouldUseHeuristicPlanner(): bool
    {
        if (config('finance_assistant.planner') === 'heuristic') {
            return true;
        }

        return app()->environment('testing') && (bool) config('finance_assistant.heuristic_planner_in_testing', true);
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array{from: ?string, to: ?string}|null
     */
    private function resolveOverallPeriod(array $facts): ?array
    {
        foreach ($facts as $fact) {
            $period = $fact['data']['period'] ?? null;
            if (is_array($period) && isset($period['from'], $period['to'])) {
                return [
                    'from' => (string) $period['from'],
                    'to' => (string) $period['to'],
                ];
            }
        }

        return null;
    }
}
