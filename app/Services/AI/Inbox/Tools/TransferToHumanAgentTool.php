<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Inbox\InboxHumanHandoffService;

/**
 * Escalate the current conversation to the human support team.
 */
final class TransferToHumanAgentTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly InboxHumanHandoffService $handoffService,
    ) {}

    public function name(): string
    {
        return 'transfer_to_human_agent';
    }

    public function description(): string
    {
        return 'Escalate and transfer the conversation to a human agent when requested by the customer or when human review is required.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Low,
            accessMode: InboxAgentToolAccessMode::Write,
            requiresConfirmation: false,
            maxCallsPerRun: 1,
        );
    }

    public function inputSchema(): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Brief summary reason for escalating to the human team.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $reason = isset($arguments['reason']) && is_string($arguments['reason']) ? trim($arguments['reason']) : null;

        if ($context->conversation->id <= 0 || ! $context->message->exists) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'transfer_to_human_agent',
                'conversation_required',
                'Live conversation context is required to transfer to a human agent.',
            );
        }

        $transferred = $this->handoffService->recordCustomerHandoff($context->conversation, $context->message);

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'transfer_to_human_agent',
            [
                'transferred' => $transferred,
                'reason' => $reason,
                'status' => $transferred ? 'handoff_requested' : 'already_handed_off',
            ],
        );
    }
}
