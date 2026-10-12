<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\Support\TypedList;

final readonly class AiContext
{
    /** @var list<ContextMessage> */
    public array $recentMessages;

    /** @var array<string, mixed> */
    public array $customerContext;

    /** @var array<string, mixed> */
    public array $employeeContext;

    /** @var array<string, mixed> */
    public array $workspacePolicyContext;

    /**
     * @param  list<ContextMessage>  $recentMessages
     * @param  array<string, mixed>  $customerContext
     * @param  array<string, mixed>  $employeeContext
     * @param  array<string, mixed>  $workspacePolicyContext
     */
    public function __construct(
        public string $customerMessage,
        array $recentMessages,
        public ?string $conversationSummary,
        array $customerContext,
        array $employeeContext,
        public ResolvedIntent $intent,
        public ReferenceResolution $references,
        public ConversationState $conversationState,
        array $workspacePolicyContext,
    ) {
        $this->recentMessages = TypedList::of($recentMessages, ContextMessage::class, 'Context messages');
        $this->customerContext = $customerContext;
        $this->employeeContext = $employeeContext;
        $this->workspacePolicyContext = $workspacePolicyContext;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_message' => $this->customerMessage,
            'recent_messages' => array_map(static fn (ContextMessage $message): array => $message->toArray(), $this->recentMessages),
            'conversation_summary' => $this->conversationSummary,
            'customer_context' => $this->customerContext,
            'employee_context' => $this->employeeContext,
            'intent' => $this->intent->toArray(),
            'references' => $this->references->toArray(),
            'conversation_state' => $this->conversationState->toArray(),
            'workspace_policy_context' => $this->workspacePolicyContext,
        ];
    }
}
