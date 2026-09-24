<?php

namespace App\Services\Finance\Assistant\Agent;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\AI\AiUsageAccountant;
use App\Services\AI\Contracts\GeminiInteractionsClient;
use App\Services\AI\Gemini\GeminiFunctionCall;
use App\Services\AI\Gemini\GeminiInteraction;
use App\Services\AI\Gemini\GeminiInteractionRequest;
use App\Services\AI\Gemini\GeminiInteractionsException;
use App\Services\AI\Gemini\GeminiInteractionsHttpClient;
use App\Services\AI\Gemini\GeminiUsage;
use App\Services\Audit\AuditLogService;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Assistant\Conversation\FinanceAgentContextBuilder;
use App\Services\Finance\Assistant\Conversation\FinanceAgentConversationContext;
use App\Services\Finance\Assistant\FinanceAssistantAiUnavailableException;
use App\Services\Finance\Assistant\FinanceAssistantOrchestrator;
use App\Services\Finance\Assistant\FinanceAssistantQuestionSemantics;
use App\Services\Finance\Assistant\FinanceAssistantToolExecutor;
use App\Services\Finance\Assistant\FinanceAssistantToolRegistry;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\Assistant\FinancialAssistantMetricRegistry;
use App\Services\Finance\Assistant\PeriodNotSupportedException;
use App\Services\Finance\Assistant\Security\FinanceAgentRunState;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class FinanceAgentOrchestrator
{
    /** @var list<array<string, mixed>> */
    public array $lastTrace = [];

    public ?string $lastInteractionId = null;

    public int $lastTokenTotal = 0;

    /**
     * Interaction IDs created during the current ask() only.
     *
     * @var array<string, true>
     */
    private array $ownedInteractionIds = [];

    public function __construct(
        private readonly GeminiInteractionsClient $gemini,
        private readonly FinanceAssistantToolRegistry $registry,
        private readonly FinanceAssistantToolExecutor $executor,
        private readonly FinanceAgentFunctionDeclarations $declarations,
        private readonly FinanceAgentResultMinimizer $minimizer,
        private readonly FinanceAgentGrounding $grounding,
        private readonly FinanceAssistantQuestionSemantics $semantics,
        private readonly FinanceAssistantOrchestrator $legacy,
        private readonly FinanceAgentContextBuilder $contextBuilder,
        private readonly FeatureAccessService $featureAccess,
        private readonly AiUsageAccountant $usageAccountant,
        private readonly AuditLogService $auditLogService,
        private readonly WorkspaceContext $workspaceContext,
        private readonly AiSafety $safety,
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
        ?FinanceAgentConversationContext $conversationContext = null,
        ?string $usageEventKey = null,
        bool $consumeUsage = true,
    ): array {
        $started = microtime(true);
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('Question is required.');
        }

        $this->resetRequestState();
        $usageEventKey = $usageEventKey ?? $conversationContext?->usageEventKey;
        $this->featureAccess->assertWithinLimit($workspace, 'ai_usage', 1);
        $this->featureAccess->assertWithinLimit($workspace, 'ai_tokens', 256);

        $semanticBlock = $this->semantics->evaluate($question);
        if ($semanticBlock !== null) {
            return $this->legacy->ask($workspace, $user, $question, $locale, $usageEventKey, $consumeUsage);
        }

        if (! filled(config('services.google_ai_studio.key')) && ! $this->usingFakeClient()) {
            return $this->plannerFallback($workspace, $user, $question, $locale, $usageEventKey, $consumeUsage, 'provider_unconfigured');
        }

        try {
            return $this->runAgent($workspace, $user, $question, $locale, $started, $conversationContext, $usageEventKey, $consumeUsage);
        } catch (GeminiInteractionsException $exception) {
            Log::warning('finance.assistant.agent.gemini_failed', [
                'workspace_id' => $workspace->id,
                'status' => $exception->statusCode,
                'correlation_id' => $this->correlationId(),
            ]);

            return $this->plannerFallback($workspace, $user, $question, $locale, $usageEventKey, $consumeUsage, 'provider_error');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runAgent(
        Workspace $workspace,
        User $user,
        string $question,
        string $locale,
        float $started,
        ?FinanceAgentConversationContext $conversationContext,
        ?string $usageEventKey,
        bool $consumeUsage,
    ): array {
        $model = $this->safety->resolveModel('google_ai_studio', (string) config('ai.google_ai_studio.model'));
        $tools = $this->declarations->tools();
        $system = FinanceAgentSystemPrompt::forLocale($locale);
        if ($conversationContext !== null) {
            $system .= "\n".$this->contextBuilder->systemSuffix($conversationContext->conversation);
        }
        $maxRounds = max(1, (int) config('finance_assistant.max_tool_rounds', 5));
        $maxCalls = max(1, (int) config('finance_assistant.max_tool_calls_per_request', 10));

        $usage = new GeminiUsage;
        $results = [];
        $minimized = [];
        $seen = [];
        $callCount = 0;
        $interactionId = null;
        $stopReason = null;
        $input = $this->turnInput($conversationContext, $question);

        $interaction = $this->startInteraction(
            $model,
            $input,
            $system,
            $tools,
            $conversationContext,
        );
        $usage = $usage->plus($interaction->usage);
        $this->rememberOwnedInteraction($interaction->id);
        $interactionId = $this->ownedPreviousId($interaction->id) ?? $interactionId;

        for ($round = 1; $round <= $maxRounds; $round++) {
            if ($interaction->functionCalls === []) {
                break;
            }

            $roundCalls = [];
            $functionResults = [];

            foreach ($interaction->functionCalls as $call) {
                if ($callCount >= $maxCalls) {
                    $stopReason = 'max_tool_calls';
                    break 2;
                }

                $callCount++;
                $executed = $this->executeCall($workspace, $user, $call, $seen, $results, $minimized);
                $roundCalls[] = [
                    'gemini' => $call->name,
                    'tool' => $executed['internal'],
                    'duplicate' => $executed['duplicate'],
                    'arguments_safe' => $executed['arguments_safe'],
                    'result_safe' => $executed['minimized'],
                    'permission_result' => $executed['permission_result'],
                    'duration_ms' => $executed['duration_ms'],
                    'success' => $executed['success'],
                ];
                $functionResults[] = [
                    'type' => 'function_result',
                    'name' => $this->declarations->geminiName($executed['internal'] !== '' ? $executed['internal'] : $call->name),
                    'call_id' => $call->id,
                    'result' => [
                        ['type' => 'text', 'text' => json_encode($executed['minimized'], JSON_UNESCAPED_UNICODE)],
                    ],
                ];
            }

            $this->lastTrace[] = [
                'round' => $round,
                'calls' => $roundCalls,
            ];

            if ($functionResults === []) {
                break;
            }

            try {
                $interaction = $this->gemini->create(new GeminiInteractionRequest(
                    model: $model,
                    input: $functionResults,
                    systemInstruction: $system,
                    tools: $tools,
                    previousInteractionId: $this->ownedPreviousId($interactionId),
                    store: true,
                    generationConfig: [
                        'temperature' => 0.2,
                        'max_output_tokens' => $this->safety->capMaxTokens((int) config('ai.google_ai_studio.max_tokens', 1024)),
                    ],
                ));
            } catch (GeminiInteractionsException $exception) {
                $stopReason = 'gemini_followup_failed';
                Log::warning('finance.assistant.agent.followup_failed', [
                    'workspace_id' => $workspace->id,
                    'status' => $exception->statusCode,
                ]);
                break;
            }

            $usage = $usage->plus($interaction->usage);
            $this->rememberOwnedInteraction($interaction->id);
            $owned = $this->ownedPreviousId($interaction->id);
            if ($owned !== null) {
                $interactionId = $owned;
            }
        }

        if ($interaction->functionCalls !== [] && $stopReason === null) {
            $stopReason = 'max_tool_rounds';
        }

        $answer = $this->safety->sanitizeReply($interaction->outputText);
        if ($answer === '' || ! $this->grounding->answerIsGrounded($answer, $minimized)) {
            $answer = $this->grounding->fallbackAnswer($minimized, $locale);
        }

        return $this->finish(
            $workspace,
            $user,
            $question,
            $results,
            $answer,
            $started,
            $usage,
            $interactionId,
            $model,
            $stopReason,
            $usageEventKey,
            $conversationContext,
            $consumeUsage,
        );
    }

    /**
     * @param  array<string, FinanceAssistantToolResult>  $seen
     * @param  list<FinanceAssistantToolResult>  $results
     * @param  list<array<string, mixed>>  $minimized
     * @return array{
     *     internal: string,
     *     minimized: array<string, mixed>,
     *     duplicate: bool,
     *     arguments_safe: array<string, mixed>,
     *     permission_result: string,
     *     duration_ms: int,
     *     success: bool
     * }
     */
    private function executeCall(
        Workspace $workspace,
        User $user,
        GeminiFunctionCall $call,
        array &$seen,
        array &$results,
        array &$minimized,
    ): array {
        $internal = $this->declarations->internalName($call->name);
        $arguments = $this->sanitizeArguments($call->arguments);
        $fingerprint = $internal.'|'.json_encode($this->canonicalArguments($arguments));
        $started = microtime(true);

        if (isset($seen[$fingerprint])) {
            $cached = $seen[$fingerprint];

            return $this->traceCall($cached, $arguments, true, $started);
        }

        $result = $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $internal, $arguments): FinanceAssistantToolResult {
            return $this->safeExecute($workspace, $user, $internal, $arguments);
        });

        $seen[$fingerprint] = $result;
        $results[] = $result;
        $minimized[] = $this->minimizer->minimize($result);

        return $this->traceCall($result, $arguments, false, $started);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     internal: string,
     *     minimized: array<string, mixed>,
     *     duplicate: bool,
     *     arguments_safe: array<string, mixed>,
     *     permission_result: string,
     *     duration_ms: int,
     *     success: bool
     * }
     */
    private function traceCall(
        FinanceAssistantToolResult $result,
        array $arguments,
        bool $duplicate,
        float $started,
    ): array {
        $reason = (string) ($result->reason ?? ($result->available ? 'allowed' : 'unavailable'));

        return [
            'internal' => $result->tool,
            'minimized' => $this->minimizer->minimize($result),
            'duplicate' => $duplicate,
            'arguments_safe' => $arguments,
            'permission_result' => mb_substr($reason !== '' ? $reason : ($result->available ? 'allowed' : 'unavailable'), 0, 32),
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'success' => $result->available,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function safeExecute(Workspace $workspace, User $user, string $internal, array $arguments): FinanceAssistantToolResult
    {
        try {
            $this->registry->get($internal);
        } catch (InvalidArgumentException) {
            return FinanceAssistantToolResult::unavailable($internal !== '' ? $internal : 'unknown', 'unknown_tool');
        }

        try {
            return $this->executor->execute($workspace, $user, [
                'name' => $internal,
                'arguments' => $arguments,
            ]);
        } catch (PeriodNotSupportedException $exception) {
            return FinanceAssistantToolResult::unavailable($internal, 'period_not_supported', [
                'requested_period' => $exception->requestedPeriod,
            ]);
        } catch (ValidationException) {
            return FinanceAssistantToolResult::unavailable($internal, 'invalid_arguments');
        } catch (InvalidArgumentException $exception) {
            $reason = str_contains($exception->getMessage(), 'Unknown') ? 'unknown_tool' : 'invalid_arguments';

            return FinanceAssistantToolResult::unavailable($internal, $reason);
        } catch (Throwable) {
            return FinanceAssistantToolResult::unavailable($internal, 'tool_error');
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function sanitizeArguments(array $arguments): array
    {
        foreach (array_keys($arguments) as $key) {
            $name = strtolower((string) $key);
            if (
                $name === 'id'
                || str_ends_with($name, '_id')
                || in_array($name, [
                    'workspace',
                    'tenant',
                    'role',
                    'permission',
                    'permissions',
                    'previous_interaction_id',
                    'interaction_id',
                ], true)
            ) {
                unset($arguments[$key]);
            }
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function canonicalArguments(array $arguments): array
    {
        ksort($arguments);

        return $arguments;
    }

    /**
     * @param  list<FinanceAssistantToolResult>  $results
     * @return array<string, mixed>
     */
    private function finish(
        Workspace $workspace,
        User $user,
        string $question,
        array $results,
        string $answer,
        float $started,
        GeminiUsage $usage,
        ?string $interactionId,
        string $model,
        ?string $stopReason,
        ?string $usageEventKey = null,
        ?FinanceAgentConversationContext $conversationContext = null,
        bool $consumeUsage = true,
    ): array {
        $this->lastInteractionId = $interactionId;
        $this->lastTokenTotal = $usage->totalTokens;
        $eventKey = $usageEventKey ?: 'finance-assistant:'.sha1($workspace->id.'|'.$user->id.'|'.$question.'|'.now()->toDateString());
        if ($consumeUsage && $this->usageAccountant->claim((int) $workspace->id, $eventKey)) {
            $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
            if ($usage->totalTokens > 0) {
                $this->featureAccess->consumeUsage($workspace, 'ai_tokens', $usage->totalTokens, enforce: true);
            }
        }

        $facts = array_map(function (FinanceAssistantToolResult $result): array {
            $fact = $result->toFactArray();
            $fact['data'] = $this->metrics->enrichFactData($fact['data']);

            return $fact;
        }, $results);
        $toolsUsed = array_values(array_unique(array_map(fn (FinanceAssistantToolResult $result): string => $result->tool, $results)));
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $roundCount = count($this->lastTrace);

        Log::info('finance.assistant.ask', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'conversation_id' => $conversationContext?->conversation->id,
            'tools' => $toolsUsed,
            'duration_ms' => $durationMs,
            'success' => true,
            'agent' => true,
            'round_count' => $roundCount,
            'correlation_id' => $this->correlationId(),
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
                'conversation_id' => $conversationContext?->conversation->id,
                'interaction_id' => $interactionId,
                'round_count' => $roundCount,
                'tool_calls' => $toolsUsed,
                'model' => $model,
                'stop_reason' => $stopReason,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'total_tokens' => $usage->totalTokens,
                'correlation_id' => $this->correlationId(),
            ],
            workspaceId: (int) $workspace->id,
        );

        return [
            'answer' => $answer,
            'facts' => $facts,
            'tools_used' => $toolsUsed,
            'period' => $this->resolveOverallPeriod($facts),
        ];
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

    /**
     * Compatibility fallback only. Tool execution still uses the security gateway.
     *
     * @return array<string, mixed>
     */
    private function plannerFallback(
        Workspace $workspace,
        User $user,
        string $question,
        string $locale,
        ?string $usageEventKey,
        bool $consumeUsage,
        string $reason,
    ): array {
        if (! (bool) config('finance_assistant.planner_fallback', true)) {
            throw new FinanceAssistantAiUnavailableException('Finance assistant provider is unavailable.');
        }

        Log::info('finance.assistant.agent.planner_fallback', [
            'workspace_id' => $workspace->id,
            'reason' => $reason,
            'correlation_id' => $this->correlationId(),
        ]);

        return $this->legacy->ask($workspace, $user, $question, $locale, $usageEventKey, $consumeUsage);
    }

    private function correlationId(): ?string
    {
        return app(FinanceAgentRunState::class)->security?->correlationId;
    }

    private function usingFakeClient(): bool
    {
        return ! $this->gemini instanceof GeminiInteractionsHttpClient;
    }

    private function resetRequestState(): void
    {
        $this->lastTrace = [];
        $this->lastInteractionId = null;
        $this->lastTokenTotal = 0;
        $this->ownedInteractionIds = [];
    }

    private function rememberOwnedInteraction(?string $id): void
    {
        $id = trim((string) $id);
        if ($id !== '') {
            $this->ownedInteractionIds[$id] = true;
        }
    }

    private function ownedPreviousId(?string $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '' || ! isset($this->ownedInteractionIds[$id])) {
            return null;
        }

        return $id;
    }

    private function turnInput(?FinanceAgentConversationContext $conversationContext, string $question): string
    {
        if ($conversationContext === null || $conversationContext->priorMessages === []) {
            return $this->safety->truncateInput($question, 2000);
        }

        return $this->safety->truncateInput(
            $this->contextBuilder->userInput($conversationContext->priorMessages, $question),
            8000,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function startInteraction(
        string $model,
        string $input,
        string $system,
        array $tools,
        ?FinanceAgentConversationContext $conversationContext,
    ): GeminiInteraction {
        $stored = $this->storedConversationInteractionId($conversationContext);
        $request = $this->interactionRequest($model, $input, $system, $tools, $stored);

        try {
            return $this->gemini->create($request);
        } catch (GeminiInteractionsException $exception) {
            if ($stored === null || $exception->statusCode < 400 || $exception->statusCode >= 500) {
                throw $exception;
            }

            Log::warning('finance.assistant.agent.expired_interaction', [
                'status' => $exception->statusCode,
            ]);

            return $this->gemini->create($this->interactionRequest($model, $input, $system, $tools, null));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function interactionRequest(
        string $model,
        string|array $input,
        string $system,
        array $tools,
        ?string $previousInteractionId,
    ): GeminiInteractionRequest {
        return new GeminiInteractionRequest(
            model: $model,
            input: $input,
            systemInstruction: $system,
            tools: $tools,
            previousInteractionId: $previousInteractionId,
            store: true,
            generationConfig: [
                'temperature' => 0.2,
                'max_output_tokens' => $this->safety->capMaxTokens((int) config('ai.google_ai_studio.max_tokens', 1024)),
            ],
        );
    }

    private function storedConversationInteractionId(?FinanceAgentConversationContext $conversationContext): ?string
    {
        if ($conversationContext === null) {
            return null;
        }

        $stored = trim((string) $conversationContext->conversation->gemini_interaction_id);
        if ($stored === '') {
            return null;
        }

        $this->rememberOwnedInteraction($stored);

        return $stored;
    }
}
