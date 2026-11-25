<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;

/**
 * Retrieve products that currently have active discounts, sale prices, or compare-at offers.
 */
final class GetActiveOffersTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'get_active_offers';
    }

    public function description(): string
    {
        return 'Retrieve active product discounts and promotional offers in this workspace, sorted by highest discount percentage.';
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
            'query' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional keyword to filter offers.',
            ],
            'category' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional category filter.',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Maximum offers to return (up to 12).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : null;
        $category = isset($arguments['category']) && is_string($arguments['category']) ? trim($arguments['category']) : null;
        $limit = max(1, min(12, (int) ($arguments['limit'] ?? 8)));

        $offers = $this->catalogRetrieval->activeOffersForAi($context->workspace, $query, $category, $limit);

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_active_offers',
            [
                'has_active_offers' => $offers !== [],
                'count' => count($offers),
                'products' => $offers,
            ],
        );
    }
}
