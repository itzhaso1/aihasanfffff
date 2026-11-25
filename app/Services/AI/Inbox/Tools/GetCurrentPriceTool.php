<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;

/**
 * Retrieve authoritative current price, sale discount, and compare-at price for an item or variant.
 */
final class GetCurrentPriceTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'get_current_price';
    }

    public function description(): string
    {
        return 'Get the authoritative current price, base price, active promotional/sale price, compare-at price, and currency for a product or specific variant.';
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
            'variant_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional specific variant ID.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = isset($arguments['item_id']) && is_numeric($arguments['item_id']) ? (int) $arguments['item_id'] : null;
        $productName = isset($arguments['product_name']) && is_string($arguments['product_name']) ? trim($arguments['product_name']) : null;
        $variantId = isset($arguments['variant_id']) && is_numeric($arguments['variant_id']) ? (int) $arguments['variant_id'] : null;

        if (($itemId === null || $itemId <= 0) && ($productName === null || $productName === '')) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_current_price',
                'invalid_arguments',
                'Provide item_id or product_name.',
            );
        }

        $row = $this->catalogRetrieval->findItemForAi($context->workspace, $itemId, $productName);
        if ($row === null) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_current_price',
                ['found' => false],
            );
        }

        $target = $row;
        $variantName = null;
        if ($variantId !== null && $variantId > 0) {
            foreach ($row['variants'] ?? [] as $v) {
                if (is_array($v) && (int) ($v['id'] ?? 0) === $variantId) {
                    $target = $v;
                    $variantName = $v['name'] ?? null;
                    break;
                }
            }
        }

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_current_price',
            array_filter([
                'found' => true,
                'catalog_item_id' => $row['catalog_item_id'],
                'product_name' => $row['name'],
                'variant_id' => $variantId && $variantName !== null ? $variantId : null,
                'variant_name' => $variantName,
                'price' => $target['price'] ?? $row['price'] ?? null,
                'base_price' => $target['base_price'] ?? $row['base_price'] ?? null,
                'sale_price' => $target['sale_price'] ?? null,
                'compare_at_price' => $target['compare_at_price'] ?? null,
                'has_active_offer' => $target['has_active_offer'] ?? false,
                'discount_percent' => $target['discount_percent'] ?? null,
                'currency' => $target['currency'] ?? $row['currency'] ?? 'SAR',
                'tax_presentation' => $row['tax_presentation'] ?? null,
                'price_valid_until' => $row['price_valid_until'] ?? null,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }
}
