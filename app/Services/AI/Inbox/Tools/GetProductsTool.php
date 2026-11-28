<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Support\Chat\CatalogAvailabilityFact;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Authoritative active-catalog product listing and lookup tool (no disabled, deleted, or invented items).
 */
final class GetProductsTool implements InboxAgentSecuredTool
{
    public const MAX_LIMIT = 12;

    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'get_products';
    }

    public function description(): string
    {
        return 'Retrieve active, verified products from the workspace catalog database (supports listing catalog or searching by query/category/stock/budget). If no matching active item exists, returns empty products plus real in-stock catalog alternatives.';
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
                'description' => 'Optional search keywords. Omit or pass empty string to list active catalog products.',
            ],
            'category' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional category filter.',
            ],
            'in_stock_only' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true to return only currently in-stock items.',
            ],
            'on_sale_only' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true to return only discounted/promotional items.',
            ],
            'max_price' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Optional maximum unit price filter.',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Maximum number of items to return (max '.self::MAX_LIMIT.').',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
        $limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 8)));

        $filters = array_filter([
            'category' => isset($arguments['category']) && is_string($arguments['category']) ? trim($arguments['category']) : null,
            'in_stock_only' => $arguments['in_stock_only'] ?? null,
            'on_sale_only' => $arguments['on_sale_only'] ?? null,
            'max_price' => $arguments['max_price'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            if ($query === '' && $filters === []) {
                $rows = $this->catalogRetrieval->searchForAiFiltered($context->workspace, '', ['sort_by' => 'recommended'], $limit);
            } elseif ($filters !== []) {
                $rows = $this->catalogRetrieval->searchForAiFiltered($context->workspace, $query, $filters, $limit);
            } else {
                $rows = $this->catalogRetrieval->searchForAi($context->workspace, $query, $limit);
            }

            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row !== []));
            $alternatives = $rows === []
                ? $this->catalogRetrieval->availableAlternativesForAi($context->workspace, limit: 4)
                : [];

            Log::info('catalog.products.searched', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'tool' => 'get_products',
                'query' => mb_substr($query, 0, 120),
                'returned_count' => count($rows),
                'alternatives_count' => count($alternatives),
            ]);

            $serialize = static fn (array $row): array => [
                'catalog_item_id' => $row['catalog_item_id'] ?? null,
                'name' => $row['name'] ?? null,
                'description' => $row['description'] ?? null,
                'price' => $row['price'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'has_active_offer' => $row['has_active_offer'] ?? false,
                'currency' => $row['currency'] ?? 'SAR',
                'stock' => $row['stock'] ?? null,
                'availability' => CatalogAvailabilityFact::token($row['availability'] ?? null),
                'available_for_sale' => CatalogAvailabilityFact::token($row['availability'] ?? null) === 'in_stock',
                'category' => $row['category'] ?? null,
                'variants' => $row['variants'] ?? [],
            ];

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_products',
                [
                    'query' => $query,
                    'product_found' => $rows !== [],
                    'count' => count($rows),
                    'products' => array_map($serialize, $rows),
                    'available_alternatives' => array_map($serialize, $alternatives),
                    'message' => $rows === []
                        ? 'المنتج غير موجود أو غير متاح حالياً في قائمة المنتجات.'
                        : null,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.get_products_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'message' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_products',
                'search_failed',
                'تعذر فحص قائمة المنتجات في الوقت الحالي.',
            );
        }
    }
}
