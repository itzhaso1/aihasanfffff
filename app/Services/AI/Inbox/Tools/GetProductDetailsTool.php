<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;

/**
 * Retrieve complete authoritative details for a single workspace catalog product.
 */
final class GetProductDetailsTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'get_product_details';
    }

    public function description(): string
    {
        return 'Retrieve complete details, specifications, pricing, active discounts, stock, policies, and variants for a specific catalog product by item_id, sku, or product_name.';
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
            'sku' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Product or variant SKU.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = isset($arguments['item_id']) && is_numeric($arguments['item_id']) ? (int) $arguments['item_id'] : null;
        $productName = isset($arguments['product_name']) && is_string($arguments['product_name']) ? trim($arguments['product_name']) : null;
        $sku = isset($arguments['sku']) && is_string($arguments['sku']) ? trim($arguments['sku']) : null;

        if (($itemId === null || $itemId <= 0) && ($productName === null || $productName === '') && ($sku === null || $sku === '')) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_product_details',
                'invalid_arguments',
                'Provide item_id, sku, or product_name.',
            );
        }

        $row = $this->catalogRetrieval->findItemForAi($context->workspace, $itemId, $productName, $sku);
        if ($row === null) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_product_details',
                [
                    'found' => false,
                    'message' => 'لم يتم العثور على هذا المنتج في الكتالوج الحالي.',
                ],
            );
        }

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_product_details',
            [
                'found' => true,
                'product' => $row,
            ],
        );
    }
}
