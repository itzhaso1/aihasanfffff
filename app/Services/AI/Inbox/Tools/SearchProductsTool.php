<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Persona\InboxAgentVoiceResolver;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox agent catalog search over Chat Catalog (not Finance Product / POS).
 */
final class SearchProductsTool implements InboxAgentSecuredTool
{
    public const MAX_LIMIT = 12;

    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Search the live chat catalog for this workspace. Products are a knowledge reference (name, description, price, features, terms). Do not use results to sell, send payment links, or deliver files.';
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
        $limit = (int) ($arguments['limit'] ?? 8);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        try {
            $rows = array_values(array_filter(
                $this->catalogRetrieval->searchForPrompt($context->workspace, $query, $limit),
                static fn (array $row): bool => $row !== [],
            ));

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'search_products',
                [
                    'query' => $query,
                    'count' => count($rows),
                    'catalog_untrusted_context' => InboxAgentVoiceResolver::withoutVoiceSettings(
                        $this->catalogRetrieval->untrustedCommunicationContext($context->workspace),
                    ),
                    'products' => array_map(static fn (array $row): array => [
                        'name' => $row['name'] ?? null,
                        'description' => $row['description'] ?? null,
                        'sku' => $row['sku'] ?? null,
                        'price' => $row['price'] ?? null,
                        'display_price' => $row['display_price'] ?? null,
                        'price_display' => $row['price_display'] ?? null,
                        'currency' => $row['currency'] ?? null,
                        'tax_presentation' => $row['tax_presentation'] ?? null,
                        'stock' => $row['stock'] ?? null,
                        'availability' => $row['availability'] ?? null,
                        'availability_message' => $row['availability_message'] ?? null,
                        'category' => $row['category'] ?? null,
                        'seller_display_name' => $row['seller_display_name'] ?? null,
                        'attributes' => $row['attributes'] ?? [],
                        'highlights' => $row['highlights'] ?? [],
                        'warnings' => $row['warnings'] ?? null,
                        'terms' => $row['terms'] ?? null,
                        'variants' => $row['variants'] ?? [],
                        'policies' => $row['policies'] ?? [],
                        'ai_safe_profile' => $row['ai_safe_profile'] ?? [],
                    ], $rows),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.search_products_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'message' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'search_products',
                'search_failed',
                'Product search is temporarily unavailable.',
            );
        }
    }
}
