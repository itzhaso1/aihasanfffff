<?php

namespace App\Services\AI\Inbox;

use App\Services\AI\AiProviderManager;
use App\Services\AI\AiSafety;
use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Inbox\Security\InboxAgentSecurityPolicy;
use App\Services\AI\Inbox\Security\InboxAgentToolRunBudget;
use App\Services\AI\Inbox\Tools\InboxAgentProviderToolCallParser;
use App\Services\AI\Inbox\Tools\InboxAgentToolDefinition;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutor;
use App\Services\AI\Inbox\Tools\InboxAgentToolMessageBuilder;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use Throwable;

/**
 * Executes the Inbox agent iteration loop.
 *
 * Timeout semantics: {@see config('ai.inbox_agent.timeout_seconds')} is a cooperative
 * wall-clock budget checked between iterations only. It does not cancel an in-flight
 * provider HTTP request and is not a substitute for provider-level HTTP timeouts.
 *
 * Iteration vs tool rounds:
 * - max_iterations limits provider/LLM calls
 * - max_tool_rounds limits LLM→tool execution→LLM cycles
 */
class InboxAgentLoop
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiSafety $safety,
        private readonly InboxAgentToolRegistry $toolRegistry,
        private readonly InboxAgentToolExecutor $toolExecutor,
        private readonly InboxAgentProviderToolCallParser $toolCallParser,
        private readonly InboxAgentToolMessageBuilder $toolMessageBuilder,
    ) {}

    public function run(InboxAgentContext $context): InboxAgentResult
    {
        $startedAt = microtime(true);
        $maxIterations = max(1, (int) config('ai.inbox_agent.max_iterations', 3));
        $maxToolRounds = max(0, (int) config('ai.inbox_agent.max_tool_rounds', 5));
        $timeoutSeconds = max(1, (int) config('ai.inbox_agent.timeout_seconds', 60));

        /** @var list<InboxAgentRun> $runs */
        $runs = [];
        $toolRounds = 0;
        $toolCallsTotal = 0;
        $toolOutcome = 'none';
        $totalTokensUsed = 0;
        $toolBudget = new InboxAgentToolRunBudget(app(InboxAgentSecurityPolicy::class));

        for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
            $elapsed = microtime(true) - $startedAt;
            if ($elapsed > $timeoutSeconds) {
                throw InboxAgentException::timeout($timeoutSeconds);
            }

            $provider = $this->providerManager->resolve($context->providerName);
            $toolDefinitions = $this->resolveToolDefinitions($provider);

            try {
                $providerResult = $provider->generate(
                    messages: $context->messages,
                    model: $context->model,
                    temperature: $context->temperature,
                    maxTokens: $context->maxTokens,
                    toolDefinitions: $toolDefinitions,
                );
            } catch (Throwable $exception) {
                $runs[] = new InboxAgentRun(
                    iteration: $iteration,
                    status: 'failed',
                    failureReason: $exception->getMessage(),
                    toolRound: $toolRounds,
                );

                $metadata = $this->buildFailureMetadata($context, $iteration, $startedAt, $runs, $toolRounds, $toolCallsTotal, $toolOutcome);
                $metadata['agent_tokens_unknown'] = true;

                return InboxAgentResult::failed(
                    failureReason: $exception->getMessage(),
                    iterations: $iteration,
                    messages: $context->messages,
                    metadata: $metadata,
                    runs: $runs,
                    tokensUsed: null,
                );
            }

            if (! is_array($providerResult)) {
                throw InboxAgentException::malformedResult('Provider result is not an array.');
            }

            if (! array_key_exists('content', $providerResult)) {
                throw InboxAgentException::malformedResult('Provider result is missing a content field.');
            }

            $iterationTokens = (int) ($providerResult['tokens_used'] ?? 0);
            $totalTokensUsed += $iterationTokens;

            $toolCalls = $this->toolCallParser->parse($providerResult);

            if ($toolCalls !== []) {
                if ($this->toolRegistry->count() === 0) {
                    return InboxAgentResult::failed(
                        failureReason: 'Tool calls were returned but no tools are registered.',
                        iterations: $iteration,
                        messages: $context->messages,
                        metadata: $this->buildFailureMetadata($context, $iteration, $startedAt, $runs, $toolRounds, $toolCallsTotal, 'attempted'),
                        runs: $runs,
                        tokensUsed: $totalTokensUsed > 0 ? $totalTokensUsed : null,
                    );
                }

                $toolRounds++;
                if ($toolRounds > $maxToolRounds) {
                    throw InboxAgentException::maxToolRounds($maxToolRounds);
                }

                $toolCallsTotal += count($toolCalls);
                $executionResults = $this->toolExecutor->executeAll($context, $toolCalls, $toolBudget);
                $toolOutcome = $this->resolveToolOutcome($executionResults);

                $assistantContent = $this->safety->sanitizeReply((string) ($providerResult['content'] ?? ''));
                $context = $context->withMessages(
                    $this->toolMessageBuilder->appendToolRound(
                        $context->messages,
                        $toolCalls,
                        $executionResults,
                        $assistantContent,
                    )
                );

                $runs[] = new InboxAgentRun(
                    iteration: $iteration,
                    status: 'tool_round',
                    content: null,
                    tokensUsed: $iterationTokens,
                    providerRaw: is_array($providerResult['raw'] ?? null) ? $providerResult['raw'] : [],
                    toolCallsCount: count($toolCalls),
                    toolRound: $toolRounds,
                );

                continue;
            }

            $reply = $this->safety->sanitizeReply((string) ($providerResult['content'] ?? ''));

            $runs[] = new InboxAgentRun(
                iteration: $iteration,
                status: $reply !== '' ? 'success' : 'failed',
                content: $reply !== '' ? $reply : null,
                tokensUsed: $iterationTokens,
                providerRaw: is_array($providerResult['raw'] ?? null) ? $providerResult['raw'] : [],
                toolRound: $toolRounds,
            );

            if ($reply === '') {
                return InboxAgentResult::failed(
                    failureReason: 'Provider returned an empty reply.',
                    iterations: $iteration,
                    messages: $context->messages,
                    metadata: $this->buildFailureMetadata($context, $iteration, $startedAt, $runs, $toolRounds, $toolCallsTotal, $toolOutcome),
                    runs: $runs,
                    tokensUsed: $totalTokensUsed > 0 ? $totalTokensUsed : null,
                );
            }

            return InboxAgentResult::success(
                reply: $reply,
                tokensUsed: $totalTokensUsed,
                iterations: $iteration,
                messages: $context->messages,
                metadata: $this->buildMetadata($context, $iteration, $startedAt, $runs, $toolRounds, $toolCallsTotal, $toolOutcome),
                raw: is_array($providerResult['raw'] ?? null) ? $providerResult['raw'] : [],
                runs: $runs,
            );
        }

        throw InboxAgentException::maxIterations($maxIterations);
    }

    /**
     * @param  list<InboxAgentToolExecutionResult>  $results
     */
    private function resolveToolOutcome(array $results): string
    {
        if ($results === []) {
            return 'attempted';
        }

        $successes = 0;
        foreach ($results as $result) {
            if ($result->success) {
                $successes++;
            }
        }

        if ($successes === count($results)) {
            return 'succeeded';
        }

        if ($successes === 0) {
            return 'failed';
        }

        return 'partial';
    }

    /**
     * @param  list<InboxAgentRun>  $runs
     * @return array<string, mixed>
     */
    private function buildMetadata(
        InboxAgentContext $context,
        int $iterations,
        float $startedAt,
        array $runs,
        int $toolRounds,
        int $toolCallsTotal,
        string $toolOutcome,
    ): array {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'inbox_agent' => true,
            'agent_iterations' => $iterations,
            'agent_tool_rounds' => $toolRounds,
            'agent_tool_calls_total' => $toolCallsTotal,
            'agent_tool_outcome' => $toolOutcome,
            'agent_latency_ms' => $latencyMs,
            'provider' => $context->providerName,
            'model' => $context->model,
            'conversation_id' => $context->conversation->id,
            'channel' => $context->conversation->channel,
            'catalog_matched' => (bool) ($context->prompt['matched'] ?? false),
            'product_names' => $context->prompt['catalog_names'] ?? [],
            'catalog_item_names' => $context->prompt['catalog_names'] ?? [],
            'run_count' => count($runs),
            'agent_tools_enabled' => (bool) config('ai.inbox_agent.tools_enabled', true),
            'agent_tool_names' => array_map(
                static fn ($definition): string => $definition->name,
                $this->toolRegistry->definitions(),
            ),
            'agent_persona_source' => $context->personaSource,
            'agent_persona_fingerprint' => $context->personaFingerprint,
            'agent_persona_version' => $context->persona->version,
            'agent_context_fingerprint' => $context->structuredContext->metadata->fingerprint,
            'agent_context_policy_version' => $context->structuredContext->metadata->policyVersion,
            'agent_context_history_count' => $context->structuredContext->metadata->historyMessageCount,
            'agent_context_history_truncated' => $context->structuredContext->metadata->historyTruncated,
            'agent_memory_fingerprint' => $context->structuredContext->memory->retrievalFingerprint,
            'agent_memory_enabled' => $context->structuredContext->memory->enabled,
            'agent_security_correlation_id' => $context->securityCorrelationId,
        ];
    }

    /**
     * @param  list<InboxAgentRun>  $runs
     * @return array<string, mixed>
     */
    private function buildFailureMetadata(
        InboxAgentContext $context,
        int $iterations,
        float $startedAt,
        array $runs,
        int $toolRounds,
        int $toolCallsTotal,
        string $toolOutcome,
    ): array {
        return array_merge($this->buildMetadata($context, $iterations, $startedAt, $runs, $toolRounds, $toolCallsTotal, $toolOutcome), [
            'agent_status' => 'failed',
        ]);
    }

    /**
     * @return list<InboxAgentToolDefinition>
     */
    private function resolveToolDefinitions(AiProviderInterface $provider): array
    {
        if (! (bool) config('ai.inbox_agent.tools_enabled', true)) {
            return [];
        }

        if (! $provider->supportsNativeToolCalling()) {
            return [];
        }

        if ($this->toolRegistry->count() === 0) {
            return [];
        }

        return $this->toolRegistry->definitions();
    }
}
