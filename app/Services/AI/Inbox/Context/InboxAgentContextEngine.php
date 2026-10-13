<?php

namespace App\Services\AI\Inbox\Context;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryPolicy;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryRetriever;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaFingerprint;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaResolver;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Support\Chat\ChatAiRole;

/**
 * Assembles structured Inbox agent context from workspace-scoped sources.
 */
final class InboxAgentContextEngine
{
    public function __construct(
        private readonly InboxAgentPersonaResolver $personaResolver,
        private readonly InboxAgentPersonaFingerprint $personaFingerprint,
        private readonly InboxAgentMessageWindowBuilder $messageWindowBuilder,
        private readonly InboxAgentContextFingerprint $contextFingerprint,
        private readonly InboxAgentContextPolicy $policy,
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
        private readonly InboxAgentToolRegistry $toolRegistry,
        private readonly InboxAgentMemoryRetriever $memoryRetriever,
        private readonly InboxAgentMemoryPolicy $memoryPolicy,
        private readonly AiSafety $safety,
    ) {}

    public function build(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
    ): InboxAgentStructuredContext {
        $resolvedPersona = $this->personaResolver->resolve($setting);
        $customer = $this->resolveCustomer($conversation);
        $persona = $resolvedPersona['persona'];
        $personaSource = $resolvedPersona['source'];
        $personaPrint = $this->personaFingerprint->compute($persona);

        $messageWindow = $this->messageWindowBuilder->build($conversation, $message);

        $catalogRows = $this->catalogRetrieval->searchForAi($workspace, $messageWindow->currentMessage->content);
        $matchedFromHistory = false;
        if ($catalogRows === []) {
            $previousQuery = $this->previousProductQuery($messageWindow);
            if ($previousQuery !== null) {
                $catalogRows = $this->catalogRetrieval->searchForAi($workspace, $previousQuery);
                $matchedFromHistory = $catalogRows !== [];
            }
        }
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $attached = data_get($metadata, 'catalog.attached_product');
        if (is_array($attached) && $attached !== []) {
            $catalogRows = [$attached];
            $matchedFromHistory = false;
        }
        $catalogNames = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            $catalogRows
        )));

        $toolsEnabled = (bool) config('ai.inbox_agent.tools_enabled', true);
        $toolNames = $toolsEnabled ? array_map(
            static fn ($definition): string => $definition->name,
            $this->toolRegistry->definitions(),
        ) : [];

        $memoryRetrieved = $this->memoryRetriever->retrieve($workspace, $conversation, $customer);
        $memorySlice = new InboxAgentMemoryContextSlice(
            enabled: $this->memoryPolicy->enabled(),
            customerMemories: $memoryRetrieved['customer'],
            conversationMemories: $memoryRetrieved['conversation'],
            retrievalFingerprint: $memoryRetrieved['fingerprint'],
        );

        $structured = new InboxAgentStructuredContext(
            workspace: new InboxAgentWorkspaceContextSlice(
                workspaceId: (int) $workspace->id,
                workspaceName: $this->safety->truncateInput((string) ($workspace->name ?? ''), 120),
            ),
            conversation: $this->conversationSlice($conversation, $message),
            customer: $this->customerSlice($customer, $conversation),
            messageWindow: $messageWindow,
            persona: new InboxAgentPersonaContextSlice(
                persona: $persona,
                source: $personaSource,
                fingerprint: $personaPrint,
            ),
            memory: $memorySlice,
            business: $this->businessSlice($setting),
            tools: new InboxAgentToolContextSlice(
                toolsEnabled: $toolsEnabled,
                availableToolNames: $toolNames,
            ),
            catalog: new InboxAgentCatalogContextSlice(
                prefetchRows: $catalogRows,
                matchedNames: $catalogNames,
                matched: $catalogRows !== [],
                untrustedCommunication: $this->catalogRetrieval->untrustedCommunicationContext($workspace),
                matchedFromHistory: $matchedFromHistory,
            ),
            metadata: new InboxAgentContextMetadata(
                fingerprint: '',
                policyVersion: InboxAgentContextPolicy::VERSION,
                policy: $this->policy->toMetadata(),
                historyMessageCount: count($messageWindow->history),
                droppedOldestMessageCount: $messageWindow->droppedOldestCount,
                historyTruncated: $messageWindow->historyBudgetTruncated || $messageWindow->perMessageTruncated,
            ),
        );

        $fingerprint = $this->contextFingerprint->compute($structured);

        return new InboxAgentStructuredContext(
            workspace: $structured->workspace,
            conversation: $structured->conversation,
            customer: $structured->customer,
            messageWindow: $structured->messageWindow,
            persona: $structured->persona,
            memory: $structured->memory,
            business: $structured->business,
            tools: $structured->tools,
            catalog: $structured->catalog,
            metadata: new InboxAgentContextMetadata(
                fingerprint: $fingerprint,
                policyVersion: $structured->metadata->policyVersion,
                policy: $structured->metadata->policy,
                historyMessageCount: $structured->metadata->historyMessageCount,
                droppedOldestMessageCount: $structured->metadata->droppedOldestMessageCount,
                historyTruncated: $structured->metadata->historyTruncated,
            ),
        );
    }

    /**
     * Latest earlier customer message that carries product words, used when the current
     * message is a short follow-up ("كم", "ابي هذا") with nothing to search for.
     */
    private function previousProductQuery(InboxAgentMessageWindow $window): ?string
    {
        foreach (array_reverse($window->history) as $message) {
            if ($message->role !== 'customer') {
                continue;
            }
            if ($this->catalogRetrieval->tokens($message->content) !== []) {
                return $message->content;
            }
        }

        return null;
    }

    private function conversationSlice(Conversation $conversation, Message $message): InboxAgentConversationContextSlice
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $summary = null;
        foreach (['ai_summary', 'summary'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $summary = $this->safety->truncateInput(trim($value), 800);
                break;
            }
        }

        return new InboxAgentConversationContextSlice(
            conversationId: (int) $conversation->id,
            channel: (string) $conversation->channel,
            status: (string) ($conversation->status ?? 'open'),
            aiEnabled: (bool) $conversation->ai_enabled,
            customerId: (int) $conversation->customer_id,
            currentMessageId: $message->exists ? (int) $message->id : 0,
            currentMessageAt: $message->created_at?->toIso8601String(),
            metadata: $metadata,
            summary: $summary,
        );
    }

    private function customerSlice(?Customer $customer, Conversation $conversation): InboxAgentCustomerContextSlice
    {
        return new InboxAgentCustomerContextSlice(
            customerId: (int) ($customer?->id ?? $conversation->customer_id),
            displayName: $this->safety->truncateInput((string) ($customer?->name ?? ''), 120),
            phone: $this->safety->truncateInput((string) ($customer?->phone ?? ''), 40),
            channelIdentifier: $this->safety->truncateInput((string) ($conversation->external_id ?? ''), 80) ?: null,
        );
    }

    private function businessSlice(AiSetting $setting): InboxAgentBusinessContextSlice
    {
        return new InboxAgentBusinessContextSlice(
            aiRole: ChatAiRole::normalize((string) ($setting->ai_role ?? ChatAiRole::GENERAL)),
            instructions: $this->safety->redactSecrets(
                $this->safety->truncateInput((string) ($setting->instructions ?? ''), 2000)
            ),
            tone: $this->safety->redactSecrets(
                $this->safety->truncateInput((string) ($setting->tone ?? ''), 200)
            ),
            replyStyle: $this->safety->redactSecrets(
                $this->safety->truncateInput((string) ($setting->reply_style ?? ''), 200)
            ),
            rules: is_array($setting->rules) ? $setting->rules : null,
            businessInformation: is_array($setting->business_information) ? $setting->business_information : null,
        );
    }

    private function resolveCustomer(Conversation $conversation): ?Customer
    {
        if ($conversation->relationLoaded('customer') && $conversation->customer instanceof Customer) {
            return $conversation->customer;
        }

        return Customer::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('id', $conversation->customer_id)
            ->first();
    }
}
