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
use App\Services\Chat\ChatCatalogRetrievalService;

final class AiContextBuilder
{
    public function __construct(
        private readonly InboxAgentMessageWindowBuilder $messageWindowBuilder,
        private readonly IntentResolver $intentResolver,
        private readonly ReferenceResolver $referenceResolver,
        private readonly ChatCatalogRetrievalService $catalog,
        private readonly AiSafety $safety,
        private readonly EmployeeRepository $employees,
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
            workspacePolicyContext: $this->workspacePolicyContext($workspace, $request),
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
     * Preview has no conversation, so catalog policy is omitted to stay side-effect free
     * and to keep workspace merchandising from overlaying employee identity.
     *
     * @return array<string, mixed>
     */
    private function workspacePolicyContext(Workspace $workspace, AiRequest $request): array
    {
        if ($request->conversationId === null) {
            return [
                'catalog_open' => false,
                'customer_service_policy' => [],
                'business_capabilities' => $this->businessCapabilities($request),
            ];
        }

        $context = $this->catalog->untrustedCommunicationContext($workspace);
        $policy = is_array($context['customer_service_policy'] ?? null)
            ? $context['customer_service_policy']
            : [];
        $seller = is_array($context['seller'] ?? null) ? $context['seller'] : [];

        return [
            'catalog_open' => (bool) ($context['catalog_open'] ?? false),
            'customer_service_policy' => $policy,
            'business_capabilities' => $this->businessCapabilities($request, $policy, $seller),
        ];
    }

    /**
     * Product, price, and stock facts stay not_loaded until a tool returns them.
     * Policy and contact slots follow the catalog payload already attached above.
     *
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $seller
     * @return array<string, mixed>
     */
    private function businessCapabilities(AiRequest $request, array $policy = [], array $seller = []): array
    {
        $slot = static fn (string $status, ?string $source = null, ?int $count = null): array => [
            'status' => $status,
            'source' => $source,
            'count' => $count,
        ];
        $unloaded = $slot('not_loaded');
        $contact = $seller['support_contact'] ?? null;
        $hasContact = (is_string($contact) && trim($contact) !== '')
            || (is_array($contact) && $contact !== []);

        return [
            'mode' => $request->runMode->isSandboxed() ? 'test' : 'live',
            'services' => $unloaded,
            'products' => $unloaded,
            'pricing' => $unloaded,
            'availability' => $unloaded,
            'orders' => $unloaded,
            'policies' => $request->conversationId === null
                ? $unloaded
                : $slot($policy !== [] ? 'loaded' : 'empty', 'catalog.customer_service_policy', count($policy)),
            'contact' => $request->conversationId === null
                ? $unloaded
                : $slot($hasContact ? 'loaded' : 'empty', 'catalog.seller', $hasContact ? 1 : 0),
            'hours' => $unloaded,
            'location' => $unloaded,
        ];
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
