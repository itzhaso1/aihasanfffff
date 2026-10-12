<?php

declare(strict_types=1);

namespace App\AI\Tools\Adapters;

use App\AI\Core\AiRequest;
use App\AI\Tools\ToolDenial;
use App\AI\Tools\ToolRegistryException;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\Context\InboxAgentBusinessContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentCatalogContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentContextMetadata;
use App\Services\AI\Inbox\Context\InboxAgentConversationContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentCustomerContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentMemoryContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentMessageWindow;
use App\Services\AI\Inbox\Context\InboxAgentNormalizedMessage;
use App\Services\AI\Inbox\Context\InboxAgentPersonaContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentStructuredContext;
use App\Services\AI\Inbox\Context\InboxAgentToolContextSlice;
use App\Services\AI\Inbox\Context\InboxAgentWorkspaceContextSlice;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Persona\InboxAgentPersona;

/**
 * Minimal InboxAgentContext for wrapping existing catalog tools. Not the live Inbox builder.
 */
final class InboxToolContextFactory
{
    public function make(AiRequest $request): InboxAgentContext
    {
        $workspace = Workspace::query()->find($request->workspaceId);
        if (! $workspace instanceof Workspace) {
            throw ToolRegistryException::invalid(ToolDenial::WORKSPACE_DENIED);
        }

        $conversation = $this->conversation($request, $workspace);
        $message = $this->message($request, $workspace, $conversation);
        $setting = $this->setting($workspace);

        return new InboxAgentContext(
            workspace: $workspace,
            conversation: $conversation,
            message: $message,
            setting: $setting,
            structuredContext: $this->structured($workspace, $conversation, $message, $request),
            persona: $this->persona(),
            personaSource: 'hasim_tool_stub',
            personaFingerprint: 'hasim_tool_stub',
            providerName: 'none',
            model: 'none',
            temperature: 0.0,
            maxTokens: 1,
            userContent: $request->message->text,
            prompt: ['system' => '', 'catalog_names' => [], 'matched' => false],
            messages: [],
            logMeta: [],
            securityCorrelationId: 'hasim_tool_'.$workspace->id,
        );
    }

    private function conversation(AiRequest $request, Workspace $workspace): Conversation
    {
        if ($request->conversationId !== null) {
            $conversation = Conversation::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($request->conversationId)
                ->first();
            if ($conversation instanceof Conversation) {
                return $conversation;
            }
        }

        $stub = new Conversation;
        $stub->id = 0;
        $stub->workspace_id = $workspace->id;
        $stub->customer_id = null;
        $stub->external_id = null;
        $stub->channel = $request->channel ?? 'chat';

        return $stub;
    }

    private function message(AiRequest $request, Workspace $workspace, Conversation $conversation): Message
    {
        if ($request->message->messageId !== null && $conversation->id > 0) {
            $message = Message::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('conversation_id', $conversation->id)
                ->whereKey($request->message->messageId)
                ->first();
            if ($message instanceof Message) {
                return $message;
            }
        }

        $stub = new Message;
        $stub->id = $request->message->messageId ?? 0;
        $stub->workspace_id = $workspace->id;
        $stub->conversation_id = $conversation->id;
        $stub->content = $request->message->text;
        $stub->direction = 'inbound';

        return $stub;
    }

    private function setting(Workspace $workspace): AiSetting
    {
        $setting = AiSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();
        if ($setting instanceof AiSetting) {
            return $setting;
        }

        $stub = new AiSetting;
        $stub->id = 0;
        $stub->workspace_id = $workspace->id;
        $stub->ai_role = 'general';

        return $stub;
    }

    private function structured(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiRequest $request,
    ): InboxAgentStructuredContext {
        $current = new InboxAgentNormalizedMessage(
            role: 'current_customer',
            content: $request->message->text,
            messageId: $message->id > 0 ? (int) $message->id : null,
        );

        return new InboxAgentStructuredContext(
            workspace: new InboxAgentWorkspaceContextSlice((int) $workspace->id, (string) ($workspace->name ?? '')),
            conversation: new InboxAgentConversationContextSlice(
                conversationId: (int) $conversation->id,
                channel: (string) ($conversation->channel ?? 'chat'),
                status: (string) ($conversation->status ?? 'open'),
                aiEnabled: true,
                customerId: (int) ($conversation->customer_id ?? 0),
                currentMessageId: (int) $message->id,
                currentMessageAt: null,
                metadata: is_array($conversation->metadata ?? null) ? $conversation->metadata : [],
                summary: null,
            ),
            customer: new InboxAgentCustomerContextSlice((int) ($conversation->customer_id ?? 0), '', '', null),
            messageWindow: new InboxAgentMessageWindow([], $current, 0, false, false),
            persona: new InboxAgentPersonaContextSlice($this->persona(), 'hasim_tool_stub', 'hasim_tool_stub'),
            memory: new InboxAgentMemoryContextSlice(false, [], [], 'none'),
            business: new InboxAgentBusinessContextSlice('general', '', '', '', null, null),
            tools: new InboxAgentToolContextSlice(true, []),
            catalog: new InboxAgentCatalogContextSlice([], [], false),
            metadata: new InboxAgentContextMetadata('hasim_tool_stub', 1, [], 0, 0, false),
        );
    }

    private function persona(): InboxAgentPersona
    {
        return new InboxAgentPersona(
            version: 1,
            identityName: '',
            identityRole: '',
            traits: [],
            language: 'ar',
            dialect: '',
            formality: '',
            warmth: '',
            verbosity: '',
            emojiUsage: '',
            greetingStyle: '',
            salesStyle: '',
            supportStyle: '',
            behavioralRules: [],
        );
    }
}
