<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;

/**
 * Retrieve all active variants (sizes, colors, editions) with exact price and stock per variant.
 */
final class GetProductVariantsTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'get_product_variants';
    }

    public function description(): string
    {
        return 'List all active variants (sizes, colors, capacities, editions) for a catalog item, each with its own price, discount, stock, and availability.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Read,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 4,
        );
    }

    public function inputSchema(): array
    {
        return [
            'item_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Catalog item ID.',
            ],
            'product_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Product name if item_id is not known.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = isset($arguments['item_id']) && is_numeric($arguments['item_id']) ? (int) $arguments['item_id'] : null;
        $productName = isset($arguments['product_name']) && is_string($arguments['product_name']) ? trim($arguments['product_name']) : null;

        if (($itemId === null || $itemId <= 0) && ($productName === null || $productName === '')) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_product_variants',
                'invalid_arguments',
                'Provide item_id or product_name.',
            );
        }

        $row = $this->catalogRetrieval->findItemForAi($context->workspace, $itemId, $productName);
        if ($row === null) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_product_variants',
                [
                    'found' => false,
                    'variants' => [],
                    'count' => 0,
                ],
            );
        }

        $variants = is_array($row['variants'] ?? null) ? $row['variants'] : [];

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_product_variants',
            [
                'found' => true,
                'catalog_item_id' => $row['catalog_item_id'],
                'product_name' => $row['name'],
                'count' => count($variants),
                'variants' => $variants,
            ],
        );
    }
}
