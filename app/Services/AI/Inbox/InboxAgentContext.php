<?php

namespace App\Services\AI\Inbox;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\Context\InboxAgentStructuredContext;
use App\Services\AI\Inbox\Persona\InboxAgentPersona;

/**
 * Immutable execution context for a single Inbox agent run.
 */
final class InboxAgentContext
{
    /**
     * @param  array{system:string,catalog_names:list<string>,matched:bool,persona_section?:string}  $prompt
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $logMeta
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly AiSetting $setting,
        public readonly InboxAgentStructuredContext $structuredContext,
        public readonly InboxAgentPersona $persona,
        public readonly string $personaSource,
        public readonly string $personaFingerprint,
        public readonly string $providerName,
        public readonly string $model,
        public readonly float $temperature,
        public readonly int $maxTokens,
        public readonly string $userContent,
        public readonly array $prompt,
        public readonly array $messages,
        public readonly array $logMeta,
        public readonly string $securityCorrelationId,
    ) {}

    /**
     * Returns a new context with an updated message stack (for tool rounds).
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function withMessages(array $messages): self
    {
        return new self(
            workspace: $this->workspace,
            conversation: $this->conversation,
            message: $this->message,
            setting: $this->setting,
            structuredContext: $this->structuredContext,
            persona: $this->persona,
            personaSource: $this->personaSource,
            personaFingerprint: $this->personaFingerprint,
            providerName: $this->providerName,
            model: $this->model,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            userContent: $this->userContent,
            prompt: $this->prompt,
            messages: $messages,
            logMeta: $this->logMeta,
            securityCorrelationId: $this->securityCorrelationId,
        );
    }
}
