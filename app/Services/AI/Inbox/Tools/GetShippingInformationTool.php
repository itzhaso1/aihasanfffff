<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogWorkspaceInfoService;

/**
 * Retrieve authoritative store shipping/delivery policy and coverage details.
 */
final class GetShippingInformationTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogWorkspaceInfoService $workspaceInfo,
    ) {}

    public function name(): string
    {
        return 'get_shipping_information';
    }

    public function description(): string
    {
        return 'Retrieve verified shipping and delivery policies, costs, timeframes, or digital delivery notes for this store.';
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
            'city' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional destination city mentioned by the customer.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $city = isset($arguments['city']) && is_string($arguments['city']) ? trim($arguments['city']) : null;

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_shipping_information',
            $this->workspaceInfo->shippingInfo($context->workspace, $city),
        );
    }
}
