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
 * Inbox agent inventory search over workspace products/services.
 * Storefront Chat Catalog publication is not required.
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
        return 'Search this workspace\'s products, exact variants, and inventory. Match all requested size, color, length, or type constraints on the same variant row; variant_match_status and matches_question identify the verified combination. An unpublished storefront catalog must not block the search. Results are a knowledge reference (name, description, price, features, terms). Do not invent items. Do not use results to sell, send payment links, or deliver files.';
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
            'category' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional category name filter.',
            ],
            'brand' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional brand filter.',
            ],
            'in_stock_only' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true to return only available/in-stock items.',
            ],
            'on_sale_only' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true to return only discounted/promotional items.',
            ],
            'min_price' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Optional minimum unit price.',
            ],
            'max_price' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Optional maximum unit price (customer budget ceiling).',
            ],
            'sort_by' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional sort: relevance, price_asc, price_desc, discount_desc, recommended, newest.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $customerMessage = trim((string) $context->message->content);
        $attributeFilters = is_array($arguments['attribute_filters'] ?? null)
            ? $arguments['attribute_filters']
            : [];
        $filterTerms = [];
        foreach (array_slice($attributeFilters, 0, 12, true) as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $filterTerms[] = $key.' '.$value;
        }
        $baseQuery = $query !== '' ? $query : $customerMessage;
        $effectiveQuery = trim(implode(' ', array_filter([
            $baseQuery,
            ...$filterTerms,
        ])));
        $limit = (int) ($arguments['limit'] ?? 8);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $structuredFilters = array_filter([
            'category' => isset($arguments['category']) && is_string($arguments['category']) ? trim($arguments['category']) : null,
            'brand' => isset($arguments['brand']) && is_string($arguments['brand']) ? trim($arguments['brand']) : null,
            'in_stock_only' => $arguments['in_stock_only'] ?? null,
            'on_sale_only' => $arguments['on_sale_only'] ?? null,
            'min_price' => $arguments['min_price'] ?? null,
            'max_price' => $arguments['max_price'] ?? null,
            'sort_by' => isset($arguments['sort_by']) && is_string($arguments['sort_by']) ? trim($arguments['sort_by']) : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            $rows = array_values(array_filter(
                $structuredFilters !== []
                    ? $this->catalogRetrieval->searchForAiFiltered($context->workspace, $effectiveQuery, $structuredFilters, $limit)
                    : $this->catalogRetrieval->searchForAi($context->workspace, $effectiveQuery, $limit),
                static fn (array $row): bool => $row !== [],
            ));
            if ($structuredFilters === [] && $rows !== [] && $query !== '' && $customerMessage !== '') {
                $refinedRows = array_values(array_filter(
                    $this->catalogRetrieval->searchForAi(
                        $context->workspace,
                        $query.' '.$customerMessage,
                        $limit,
                    ),
                    static fn (array $row): bool => $row !== [],
                ));
                if ($this->hasVariantEvidence($refinedRows)
                    || ! $this->hasVariantEvidence($rows)) {
                    $rows = $refinedRows !== [] ? $refinedRows : $rows;
                }
            }

            $alternatives = $rows === []
                ? $this->catalogRetrieval->availableAlternativesForAi($context->workspace, limit: 4)
                : [];

            Log::info('catalog.products.searched', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'tool' => 'search_products',
                'query' => mb_substr($query, 0, 120),
                'returned_count' => count($rows),
                'alternatives_count' => count($alternatives),
            ]);

            $serializeRow = static fn (array $row): array => [
                'catalog_item_id' => $row['catalog_item_id'] ?? null,
                'name' => $row['name'] ?? null,
                'description' => $row['description'] ?? null,
                'sku' => $row['sku'] ?? null,
                'brand' => $row['brand'] ?? null,
                'price' => $row['price'] ?? null,
                'base_price' => $row['base_price'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'compare_at_price' => $row['compare_at_price'] ?? null,
                'has_active_offer' => $row['has_active_offer'] ?? false,
                'discount_percent' => $row['discount_percent'] ?? null,
                'display_price' => $row['display_price'] ?? null,
                'price_display' => $row['price_display'] ?? null,
                'currency' => $row['currency'] ?? null,
                'tax_presentation' => $row['tax_presentation'] ?? null,
                'stock' => $row['stock'] ?? null,
                'availability' => $row['availability'] ?? null,
                'availability_message' => $row['availability_message'] ?? null,
                'variant_match_status' => $row['variant_match_status'] ?? null,
                'matched_variant_ids' => $row['matched_variant_ids'] ?? [],
                'category' => $row['category'] ?? null,
                'seller_display_name' => $row['seller_display_name'] ?? null,
                'attributes' => $row['attributes'] ?? [],
                'specifications' => $row['specifications'] ?? [],
                'highlights' => $row['highlights'] ?? [],
                'warnings' => $row['warnings'] ?? null,
                'terms' => $row['terms'] ?? null,
                'variants' => $row['variants'] ?? [],
                'policies' => $row['policies'] ?? [],
                'ai_safe_profile' => $row['ai_safe_profile'] ?? [],
            ];

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'search_products',
                [
                    'query' => $query,
                    'count' => count($rows),
                    'catalog_untrusted_context' => InboxAgentVoiceResolver::withoutVoiceSettings(
                        $this->catalogRetrieval->untrustedCommunicationContext($context->workspace),
                    ),
                    'products' => array_map($serializeRow, $rows),
                    'available_alternatives' => array_map($serializeRow, $alternatives),
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

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function hasVariantEvidence(array $rows): bool
    {
        foreach ($rows as $row) {
            if (in_array($row['variant_match_status'] ?? null, ['matched', 'not_found'], true)) {
                return true;
            }
        }

        return false;
    }
}
