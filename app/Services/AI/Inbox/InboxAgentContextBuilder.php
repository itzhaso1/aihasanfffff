<?php

namespace App\Services\AI\Inbox;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\AI\Inbox\Context\InboxAgentContextEngine;
use App\Support\Chat\ChatAiRole;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Str;

class InboxAgentContextBuilder
{
    public function __construct(
        private readonly InboxAgentContextEngine $contextEngine,
        private readonly InboxAgentPromptBuilder $promptBuilder,
        private readonly AiSafety $safety,
    ) {}

    public function build(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
    ): InboxAgentContext {
        return app(WorkspaceContext::class)->runFor($workspace, fn (): InboxAgentContext => $this->buildWithinWorkspace(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));
    }

    private function buildWithinWorkspace(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
    ): InboxAgentContext {
        $structured = $this->contextEngine->build($workspace, $conversation, $message, $setting);

        $persona = $structured->persona->persona;
        $personaSource = $structured->persona->source;
        $personaFingerprint = $structured->persona->fingerprint;

        $providerName = $this->safety->resolveProvider($setting->provider);
        $model = $this->safety->resolveModel($providerName, $setting->model);
        $temperature = $this->safety->capTemperature((float) ($setting->temperature ?: $this->defaultTemperature($providerName)));
        $maxTokens = $this->safety->capMaxTokens((int) ($setting->max_tokens ?: $this->defaultMaxTokens($providerName)));

        $userContent = $structured->messageWindow->currentMessage->content;
        $prompt = $this->promptBuilder->render($structured);
        $messages = $this->promptBuilder->buildInitialMessages($structured, $prompt);

        $securityCorrelationId = 'aisec_'.Str::uuid()->toString();

        $logMeta = $this->safety->logMetadata($providerName, $model, mb_strlen($userContent), 'pending', [
            'product_names' => $prompt['catalog_names'],
            'catalog_item_names' => $prompt['catalog_names'],
            'ai_role' => ChatAiRole::normalize((string) ($setting->ai_role ?? ChatAiRole::GENERAL)),
            'conversation_id' => $conversation->id,
            'inbox_agent' => true,
            'agent_persona_source' => $personaSource,
            'agent_persona_fingerprint' => $personaFingerprint,
            'agent_persona_version' => $persona->version,
            'agent_context_fingerprint' => $structured->metadata->fingerprint,
            'agent_context_policy_version' => $structured->metadata->policyVersion,
            'agent_context_history_count' => $structured->metadata->historyMessageCount,
            'agent_context_history_truncated' => $structured->metadata->historyTruncated,
            'agent_memory_fingerprint' => $structured->memory->retrievalFingerprint,
            'agent_memory_enabled' => $structured->memory->enabled,
            'agent_security_correlation_id' => $securityCorrelationId,
        ]);

        return new InboxAgentContext(
            workspace: $workspace,
            conversation: $conversation,
            message: $message,
            setting: $setting,
            structuredContext: $structured,
            persona: $persona,
            personaSource: $personaSource,
            personaFingerprint: $personaFingerprint,
            providerName: $providerName,
            model: $model,
            temperature: $temperature,
            maxTokens: $maxTokens,
            userContent: $userContent,
            prompt: $prompt,
            messages: $messages,
            logMeta: $logMeta,
            securityCorrelationId: $securityCorrelationId,
        );
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
