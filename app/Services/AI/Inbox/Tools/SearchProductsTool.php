<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use Illuminate\Support\Facades\Log;

/**
 * Honest empty product lookup. Chat Catalog is retired, so this tool never
 * invents inventory, prices, or offerings.
 */
final class SearchProductsTool implements InboxAgentSecuredTool
{
    public const MAX_LIMIT = 12;

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Look up products or services for this workspace. There is no product catalog. Always returns an empty list. Do not invent items, prices, or availability.';
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
                'required' => true,
                'description' => 'Customer product search query.',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Maximum number of products to return (max '.self::MAX_LIMIT.').',
            ],
            'attribute_filters' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Optional structured attribute filters (e.g. {"color": "Blue", "size": "L"}).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        Log::info('inbox.agent.tool.search_products_empty', [
            'workspace_id' => $context->workspace->id,
            'conversation_id' => $context->conversation->id,
            'query_present' => $query !== '',
        ]);

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'search_products',
            [
                'query' => $query,
                'count' => 0,
                'source' => 'none',
                'catalog_untrusted_context' => [],
                'products' => [],
                'message' => 'No product catalog is available. Do not invent products, services, or prices.',
            ],
        );
    }
}
