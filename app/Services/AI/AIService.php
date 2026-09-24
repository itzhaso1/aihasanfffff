<?php

namespace App\Services\AI;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\MissingWorkspaceContextException;
use App\Exceptions\UsageLimitExceededException;
use App\Jobs\UpdateAIUsage;
use App\Models\AiLog;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentException;
use App\Services\AI\Inbox\InboxAgentOrchestrator;
use App\Services\AI\Inbox\InboxAgentResult;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AIService
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly FeatureAccessService $featureAccess,
        private readonly AiSafety $safety,
        private readonly AiUsageAccountant $usageAccountant,
        private readonly InboxAgentOrchestrator $inboxAgentOrchestrator,
    ) {}

    public function generateReply(Conversation $conversation, Message $message): string
    {
        $workspace = Workspace::query()->find($conversation->workspace_id);
        if (! $workspace) {
            throw MissingWorkspaceContextException::forQuery(Conversation::class);
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($conversation, $message, $workspace): string {
            $this->assertAiFeature($workspace);
            // Soft pre-check: block when already over hard limit (estimate 1 unit / ~256 tokens).
            $this->featureAccess->assertWithinLimit($workspace, 'ai_usage', 1);
            $this->featureAccess->assertWithinLimit($workspace, 'ai_tokens', 256);

            $setting = $this->resolveSetting($conversation);

            if ($this->inboxAgentEnabled()) {
                try {
                    $agentResult = $this->inboxAgentOrchestrator->run($workspace, $conversation, $message, $setting);

                    if ($agentResult->isSuccessful()) {
                        return $this->finalizeGeneration($workspace, $conversation, $message, $agentResult, $setting);
                    }

                    $this->writeAgentFailureLog($conversation, $message, $setting, $agentResult);

                    if ($this->inboxAgentFallbackEnabled()) {
                        Log::warning('inbox.agent.fallback', [
                            'conversation_id' => $conversation->id,
                            'message_id' => $message->exists ? $message->id : null,
                            'reason' => $agentResult->failureReason ?? 'agent_failed',
                            'iterations' => $agentResult->iterations,
                        ]);

                        return $this->finalizeLegacyGeneration($workspace, $conversation, $message, $setting, [
                            'inbox_agent_fallback' => true,
                            'inbox_agent_failure' => $agentResult->failureReason,
                        ]);
                    }

                    return $this->controlledAgentFailureMessage();
                } catch (InboxAgentException $exception) {
                    $this->writeAgentExceptionLog($conversation, $message, $setting, $exception);

                    if ($this->inboxAgentFallbackEnabled()) {
                        Log::warning('inbox.agent.fallback', [
                            'conversation_id' => $conversation->id,
                            'message_id' => $message->exists ? $message->id : null,
                            'reason' => $exception->getMessage(),
                        ]);

                        return $this->finalizeLegacyGeneration($workspace, $conversation, $message, $setting, [
                            'inbox_agent_fallback' => true,
                            'inbox_agent_failure' => $exception->getMessage(),
                        ]);
                    }

                    return $this->controlledAgentFailureMessage();
                }
            }

            return $this->finalizeLegacyGeneration($workspace, $conversation, $message, $setting);
        });
    }

    private function resolveSetting(Conversation $conversation): AiSetting
    {
        return AiSetting::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->first() ?? new AiSetting([
                'name' => 'AI Assistant',
                'instructions' => 'كن مساعدًا مهذبًا ومختصرًا.',
                'provider' => config('ai.default_provider', 'google_ai_studio'),
                'model' => $this->defaultModel(config('ai.default_provider', 'google_ai_studio')),
                'temperature' => $this->defaultTemperature(config('ai.default_provider', 'google_ai_studio')),
                'max_tokens' => $this->defaultMaxTokens(config('ai.default_provider', 'google_ai_studio')),
            ]);
    }

    /**
     * @param  array<string, mixed>  $logExtra
     */
    private function finalizeLegacyGeneration(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
        array $logExtra = [],
    ): string {
        $providerName = $this->safety->resolveProvider($setting->provider);
        $provider = $this->providerManager->resolve($providerName);
        $model = $this->safety->resolveModel($providerName, $setting->model);
        $temperature = $this->safety->capTemperature((float) ($setting->temperature ?: $this->defaultTemperature($providerName)));
        $maxTokens = $this->safety->capMaxTokens((int) ($setting->max_tokens ?: $this->defaultMaxTokens($providerName)));
        $userContent = $this->safety->truncateInput((string) ($message->content ?? ''));
        $instructions = $this->safety->truncateInput((string) ($setting->instructions ?? 'كن مساعدًا مهذبًا ومختصرًا.'), 2000);

        $products = Product::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('status', 'active')
            ->with(['variants' => fn ($query) => $query
                ->where('workspace_id', $conversation->workspace_id)
                ->where('status', 'active')])
            ->limit(20)
            ->get(['id', 'name', 'description', 'sku', 'price', 'sale_price', 'stock', 'currency', 'brand'])
            ->map(fn (Product $product): array => [
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'price' => $product->sale_price ?: $product->price,
                'stock' => $product->stock,
                'currency' => $product->currency,
                'brand' => $product->brand,
                'variants' => $product->variants
                    ->map(fn (ProductVariant $variant): array => [
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'attributes' => $variant->attributes ?? [],
                        'price' => $variant->sale_price ?: $variant->price,
                        'stock' => $variant->stock,
                    ])->values()->all(),
            ])->all();

        $messages = [
            [
                'role' => 'system',
                'content' => trim($instructions."\n\n".
                    'قواعد مهمة: استخدم فقط بيانات نفس workspace الحالية ولا تختلق بيانات غير موجودة. '.
                    "إذا كان المنتج غير متوفر أخبر العميل بذلك بوضوح.\n\n".
                    "المنتجات المتاحة داخل نفس مساحة العمل:\n".json_encode($products, JSON_UNESCAPED_UNICODE)),
            ],
            [
                'role' => 'user',
                'content' => $this->safety->wrapUntrustedUserContent($userContent),
            ],
        ];

        $logMeta = $this->safety->logMetadata($providerName, $model, mb_strlen($userContent), 'pending', array_merge([
            'product_names' => array_values(array_filter(array_map(
                static fn (array $row): string => (string) ($row['name'] ?? ''),
                $products
            ))),
            'conversation_id' => $conversation->id,
        ], $logExtra));

        try {
            $result = $provider->generate(
                messages: $messages,
                model: $model,
                temperature: $temperature,
                maxTokens: $maxTokens,
            );

            $tokensUsed = (int) ($result['tokens_used'] ?? 0);
            $reply = $this->safety->sanitizeReply((string) ($result['content'] ?? '')) ?: 'تعذر إنشاء رد مناسب حالياً.';

            $this->writeLog($conversation, $message, $logMeta, 'success', $tokensUsed, $messages, $result['raw'] ?? []);
            $this->recordUsage($workspace, $conversation, $message, $userContent, $tokensUsed);

            return $reply;
        } catch (FeatureNotAvailableException|UsageLimitExceededException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->writeLog($conversation, $message, $logMeta, 'failed', 0, $messages, [], $exception->getMessage());

            return 'تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.';
        }
    }

    private function finalizeGeneration(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        InboxAgentResult $agentResult,
        AiSetting $setting,
    ): string {
        $providerName = $this->safety->resolveProvider($setting->provider);
        $model = $this->safety->resolveModel($providerName, $setting->model);
        $userContent = $this->safety->truncateInput((string) ($message->content ?? ''));

        $logMeta = $this->safety->logMetadata($providerName, $model, mb_strlen($userContent), 'pending', array_merge(
            $agentResult->metadata,
            [
                'agent_status' => $agentResult->status,
            ],
        ));

        $this->writeLog(
            $conversation,
            $message,
            $logMeta,
            'success',
            (int) ($agentResult->tokensUsed ?? 0),
            $agentResult->messages,
            $agentResult->raw,
        );
        $this->recordUsage($workspace, $conversation, $message, $userContent, (int) ($agentResult->tokensUsed ?? 0));

        return $agentResult->reply;
    }

    private function writeAgentFailureLog(
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
        InboxAgentResult $agentResult,
    ): void {
        $providerName = $this->safety->resolveProvider($setting->provider);
        $model = $this->safety->resolveModel($providerName, $setting->model);
        $userContent = $this->safety->truncateInput((string) ($message->content ?? ''));

        $logMeta = $this->safety->logMetadata($providerName, $model, mb_strlen($userContent), 'pending', array_merge(
            $agentResult->metadata,
            [
                'agent_status' => 'failed',
                'failure_reason' => $agentResult->failureReason,
            ],
        ));

        if ($agentResult->tokensUsed === null) {
            $logMeta['agent_tokens_unknown'] = true;
        }

        $this->writeLog(
            $conversation,
            $message,
            $logMeta,
            'failed',
            (int) ($agentResult->tokensUsed ?? 0),
            $agentResult->messages,
            [],
            $agentResult->failureReason,
        );
    }

    private function writeAgentExceptionLog(
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
        InboxAgentException $exception,
    ): void {
        $providerName = $this->safety->resolveProvider($setting->provider);
        $model = $this->safety->resolveModel($providerName, $setting->model);
        $userContent = $this->safety->truncateInput((string) ($message->content ?? ''));

        $logMeta = $this->safety->logMetadata($providerName, $model, mb_strlen($userContent), 'pending', [
            'inbox_agent' => true,
            'agent_status' => 'failed',
            'failure_reason' => $exception->getMessage(),
            'provider' => $providerName,
            'model' => $model,
            'conversation_id' => $conversation->id,
            'agent_tokens_unknown' => true,
        ]);

        $this->writeLog(
            $conversation,
            $message,
            $logMeta,
            'failed',
            0,
            [],
            [],
            $exception->getMessage(),
        );
    }

    /**
     * User-visible message when the Inbox agent fails and legacy fallback is disabled.
     */
    private function controlledAgentFailureMessage(): string
    {
        return 'تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.';
    }

    /**
     * Records plan usage once per inbound message. Retries and agent→legacy fallback
     * share the same event key so internal consumption cannot double-bill.
     *
     * Note: a failed provider HTTP call may still consume vendor tokens even when
     * {@see InboxAgentResult::$tokensUsed} is null; we only bill tokens reported on success.
     */
    private function recordUsage(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        string $userContent,
        int $tokensUsed,
    ): void {
        $usageEventKey = 'aiservice:'.$conversation->id.':'.($message->exists ? (string) $message->id : 'ephemeral:'.sha1($userContent));
        if ($this->usageAccountant->claim((int) $workspace->id, $usageEventKey)) {
            $this->featureAccess->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
            if ($tokensUsed > 0) {
                $this->featureAccess->consumeUsage($workspace, 'ai_tokens', $tokensUsed, enforce: true);
            }
        }
        UpdateAIUsage::dispatch($workspace->id, 0, $usageEventKey);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<string, mixed>  $raw
     */
    private function writeLog(
        Conversation $conversation,
        Message $message,
        array $meta,
        string $status,
        int $tokensUsed,
        array $messages,
        array $raw,
        ?string $error = null,
    ): void {
        $input = $meta;
        $input['outcome'] = $status;
        $input['request_id'] = request()?->header('X-Request-Id') ?: (string) Str::uuid();
        $output = [
            'content_chars' => $status === 'success'
                ? mb_strlen($this->safety->sanitizeReply((string) ($raw['content'] ?? data_get($raw, 'candidates.0.content.parts.0.text', ''))))
                : 0,
        ];

        if ($this->safety->storesContent()) {
            $input['content_stored'] = true;
            $input['messages'] = $this->redactMessages($messages);
            $output['raw_redacted'] = $this->safety->redactSecrets(json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '');
        }

        AiLog::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->exists ? $message->id : null,
            'user_id' => auth()->id(),
            'type' => 'reply',
            'input_payload' => $input,
            'output_payload' => $output,
            'tokens_used' => $tokensUsed,
            'status' => $status,
            'error_message' => $error ? $this->safety->redactSecrets($error) : null,
        ]);
    }

    /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @return array<int, array{role:string,content:string}>
     */
    private function redactMessages(array $messages): array
    {
        return array_map(function (array $row): array {
            return [
                'role' => (string) ($row['role'] ?? 'user'),
                'content' => $this->safety->redactSecrets((string) ($row['content'] ?? '')),
            ];
        }, $messages);
    }

    private function assertAiFeature(Workspace $workspace): void
    {
        $user = auth()->user();

        if ($user) {
            $this->featureAccess->assertFeature($user, $workspace, 'ai');

            return;
        }

        if (! $this->featureAccess->workspaceHasFeature($workspace, 'ai')) {
            throw new FeatureNotAvailableException(
                feature: 'ai',
                requiredPlan: $this->featureAccess->suggestedPlanForFeature('ai'),
                message: __('ميزة الذكاء الاصطناعي غير متاحة في باقتك الحالية.'),
            );
        }
    }

    private function inboxAgentEnabled(): bool
    {
        return (bool) config('ai.inbox_agent.enabled', true);
    }

    private function inboxAgentFallbackEnabled(): bool
    {
        return (bool) config('ai.inbox_agent.fallback_enabled', true);
    }

    private function defaultModel(string $provider): string
    {
        if ($provider === 'google_ai_studio') {
            return (string) config('ai.google_ai_studio.model', config('services.google_ai_studio.model', 'gemini-2.5-flash'));
        }

        return (string) config('ai.'.$provider.'.model', config('ai.openai.model'));
    }

    private function defaultTemperature(string $provider): float
    {
        return (float) config('ai.'.$provider.'.temperature', 0.4);
    }

    private function defaultMaxTokens(string $provider): int
    {
        return (int) config('ai.'.$provider.'.max_tokens', 512);
    }
}
