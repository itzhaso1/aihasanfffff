<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Employees\EmployeeRepository;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\AI\Inbox\Context\InboxAgentMessageWindowBuilder;
use App\Services\Workspace\WorkspaceCompanyProfileService;

final class AiContextBuilder
{
    public function __construct(
        private readonly InboxAgentMessageWindowBuilder $messageWindowBuilder,
        private readonly IntentResolver $intentResolver,
        private readonly ReferenceResolver $referenceResolver,
        private readonly AiSafety $safety,
        private readonly EmployeeRepository $employees,
        private readonly WorkspaceCompanyProfileService $companyProfiles,
    ) {}

    public function build(AiRequest $request, ResolvedEmployee $employee): AiContext
    {
        $workspace = Workspace::query()->findOrFail($request->workspaceId);
        $conversation = $request->conversationId !== null ? Conversation::withoutGlobalScopes()
            ->where('workspace_id', $request->workspaceId)
            ->whereKey($request->conversationId)
            ->first()
            : null;
        $currentMessage = $this->currentMessage($request, $conversation);
        $channel = (string) ($conversation?->channel ?? $request->channel ?? 'chat');
        $metadata = is_array($conversation?->metadata) ? $conversation->metadata : [];
        $state = ConversationState::fromConversationMetadata($metadata, $employee->employeeId);

        $recentMessages = $conversation !== null
            ? $this->recentMessages($conversation, $currentMessage)
            : [];
        $summary = $this->conversationSummary($metadata);
        $references = $conversation !== null
            ? $this->referenceResolver->resolve(
                workspace: $workspace,
                conversationMetadata: $metadata,
                state: $state,
                currentMessage: $request->message->text,
                recentMessages: $recentMessages,
                channel: $channel,
            )
            : new ReferenceResolution;
        $intent = $this->intentResolver->resolve(
            message: $request->message->text,
            state: $state,
            hasProductContext: $references->resolved,
        );

        return new AiContext(
            customerMessage: $request->message->text,
            recentMessages: $recentMessages,
            conversationSummary: $summary,
            customerContext: $this->customerContext($conversation),
            employeeContext: $this->employeeContext($request, $employee),
            intent: $intent,
            references: $references,
            conversationState: $state,
            workspacePolicyContext: $this->workspacePolicyContext($request, $references),
            companyProfile: $this->companyProfiles->contextPayload($request->workspaceId),
        );
    }

    private function currentMessage(AiRequest $request, ?Conversation $conversation): Message
    {
        if ($request->message->messageId !== null && $conversation !== null) {
            $stored = Message::withoutGlobalScopes()
                ->where('workspace_id', $request->workspaceId)
                ->where('conversation_id', $conversation->id)
                ->whereKey($request->message->messageId)
                ->first();
            if ($stored instanceof Message) {
                return $stored;
            }
        }

        $message = new Message([
            'workspace_id' => $request->workspaceId,
            'conversation_id' => $conversation?->id,
            'customer_id' => $conversation?->customer_id,
            'direction' => 'inbound',
            'content' => $request->message->text,
        ]);
        if ($request->message->messageId !== null) {
            $message->id = $request->message->messageId;
        }

        return $message;
    }

    /**
     * @return list<ContextMessage>
     */
    private function recentMessages(Conversation $conversation, Message $currentMessage): array
    {
        $window = $this->messageWindowBuilder->build($conversation, $currentMessage);

        return array_map(
            static fn ($message): ContextMessage => new ContextMessage(
                role: $message->role,
                content: $message->content,
                messageId: $message->messageId,
                occurredAt: $message->occurredAt,
                channel: $message->channel,
            ),
            $window->history,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function conversationSummary(array $metadata): ?string
    {
        foreach (['ai_summary', 'summary'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->safety->truncateInput($this->safety->redactSecrets(trim($value)), 800);
            }
        }

        return null;
    }

    /**
     * Employee context is keyed by the resolved employee id. Display name stays in the
     * employee prompt layer so the core cannot branch on it.
     *
     * @return array<string, int|string|list<string>|null>
     */
    private function employeeContext(AiRequest $request, ResolvedEmployee $employee): array
    {
        $record = $this->employees->find($request->workspaceId, $employee->employeeId);
        $persona = $record?->persona;

        return [
            'employee_id' => $employee->employeeId,
            'config_version' => $employee->configVersion,
            'skill_keys' => $employee->skillKeys,
            'role' => $this->configuredString($record?->role, $this->requestString($request, 'employee_role')),
            'language' => $this->personaString($persona?->language, $this->requestString($request, 'employee_language')),
            'dialect' => $this->personaString($persona?->dialect, $this->requestString($request, 'employee_dialect')),
            'domain' => $this->configuredString($record?->training_profile, $this->requestString($request, 'domain')),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function customerContext(?Conversation $conversation): array
    {
        if ($conversation === null) {
            return ['customer_id' => null, 'display_name' => null];
        }

        $name = Customer::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereKey($conversation->customer_id)
            ->value('name');

        return [
            'customer_id' => (int) $conversation->customer_id,
            'display_name' => is_string($name) && trim($name) !== ''
                ? $this->safety->truncateInput($name, 120)
                : null,
        ];
    }

    /**
     * Inbox replies do not depend on Chat Catalog storefront settings.
     * Product, price, and availability slots follow the retrieval status for this turn.
     *
     * @return array<string, mixed>
     */
    private function workspacePolicyContext(AiRequest $request, ReferenceResolution $references): array
    {
        return [
            'catalog_open' => false,
            'catalog_required_for_reply' => false,
            'customer_service_policy' => [],
            'business_capabilities' => $this->businessCapabilities($request, $references),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessCapabilities(AiRequest $request, ReferenceResolution $references): array
    {
        $slot = static fn (string $status, ?string $source = null, ?int $count = null): array => [
            'status' => $status,
            'source' => $source,
            'count' => $count,
        ];
        $unloaded = $slot('not_loaded');
        $catalog = $this->catalogSlot($references, $slot);

        return [
            'mode' => $request->runMode->isSandboxed() ? 'test' : 'live',
            'services' => $unloaded,
            'products' => $catalog,
            'pricing' => $this->evidenceSlot($references, 'price', $catalog, $slot),
            'availability' => $this->evidenceSlot($references, 'availability', $catalog, $slot),
            'orders' => $unloaded,
            'policies' => $unloaded,
            'contact' => $unloaded,
            'hours' => $unloaded,
            'location' => $unloaded,
        ];
    }

    /**
     * @param  callable(string, ?string, ?int): array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function catalogSlot(ReferenceResolution $references, callable $slot): array
    {
        $count = count($references->catalogItems);

        $payload = match ($references->retrievalStatus) {
            'listed' => $slot('listed', 'chat_catalog', $count),
            'catalog_empty' => $slot('catalog_empty', 'chat_catalog', 0),
            'no_matching_type' => $slot('no_matching_type', 'chat_catalog', 0),
            'name_not_found' => $slot('name_not_found', 'chat_catalog', 0),
            'search_failed' => $slot('search_failed', 'chat_catalog', null),
            default => $slot('not_loaded'),
        };
        $payload['has_more'] = $references->retrievalStatus === 'listed' && $references->hasMore;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  callable(string, ?string, ?int): array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function evidenceSlot(ReferenceResolution $references, string $field, array $catalog, callable $slot): array
    {
        if ($references->retrievalStatus !== 'listed') {
            return $catalog;
        }

        foreach ($references->catalogItems as $item) {
            if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '') {
                return $slot('listed', 'chat_catalog', count($references->catalogItems));
            }
        }

        return $slot('not_loaded');
    }

    private function requestString(AiRequest $request, string $key): ?string
    {
        $value = $request->attributes[$key] ?? null;
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    private function personaString(?string $value, ?string $fallback): ?string
    {
        $configured = $this->configuredString($value);
        if ($configured === EmployeePersonaOptions::AUTO) {
            $configured = null;
        }

        return $configured ?? $fallback;
    }

    private function configuredString(?string $value, ?string $fallback = null): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' ? $text : $fallback;
    }
}
