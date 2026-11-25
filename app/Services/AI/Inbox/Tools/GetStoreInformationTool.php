<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogWorkspaceInfoService;

/**
 * Retrieve verified store hours, contact details, payment methods, return policy, and location.
 */
final class GetStoreInformationTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogWorkspaceInfoService $workspaceInfo,
    ) {}

    public function name(): string
    {
        return 'get_store_information';
    }

    public function description(): string
    {
        return 'Retrieve verified store working hours, contact options, payment methods, return/exchange/warranty policies, and location/branches.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Read,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 3,
        );
    }

    public function inputSchema(): array
    {
        return [
            'topic' => [
                'type' => 'string',
                'required' => false,
                'description' => 'One of: all, hours, contact, policies, returns, payment, location.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $topic = isset($arguments['topic']) && is_string($arguments['topic']) && trim($arguments['topic']) !== ''
            ? strtolower(trim($arguments['topic']))
            : 'all';

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_store_information',
            $this->workspaceInfo->storeInfo($context->workspace, $topic),
        );
    }
}
