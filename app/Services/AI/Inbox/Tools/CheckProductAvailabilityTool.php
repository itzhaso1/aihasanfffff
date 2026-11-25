<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Support\Chat\CatalogAvailabilityFact;

/**
 * Verify exact product or variant stock availability and fulfillable quantity.
 */
final class CheckProductAvailabilityTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'check_product_availability';
    }

    public function description(): string
    {
        return 'Check authoritative stock status and inventory policy for a product or specific variant and optional requested quantity.';
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
            'quantity' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional requested quantity (defaults to 1).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = isset($arguments['item_id']) && is_numeric($arguments['item_id']) ? (int) $arguments['item_id'] : null;
        $productName = isset($arguments['product_name']) && is_string($arguments['product_name']) ? trim($arguments['product_name']) : null;
        $variantId = isset($arguments['variant_id']) && is_numeric($arguments['variant_id']) ? (int) $arguments['variant_id'] : null;
        $quantity = max(1, (int) ($arguments['quantity'] ?? 1));

        if (($itemId === null || $itemId <= 0) && ($productName === null || $productName === '')) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'check_product_availability',
                'invalid_arguments',
                'Provide item_id or product_name.',
            );
        }

        $row = $this->catalogRetrieval->findItemForAi($context->workspace, $itemId, $productName);
        if ($row === null) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'check_product_availability',
                [
                    'found' => false,
                    'availability' => 'out_of_stock',
                ],
            );
        }

        $target = $row;
        $selectedVariant = null;
        if ($variantId !== null && $variantId > 0) {
            foreach ($row['variants'] ?? [] as $v) {
                if (is_array($v) && (int) ($v['id'] ?? 0) === $variantId) {
                    $target = $v;
                    $selectedVariant = $v;
                    break;
                }
            }
            if ($selectedVariant === null) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'check_product_availability',
                    [
                        'found' => true,
                        'catalog_item_id' => $row['catalog_item_id'],
                        'product_name' => $row['name'],
                        'variant_found' => false,
                        'availability' => 'out_of_stock',
                    ],
                );
            }
        }

        $token = CatalogAvailabilityFact::token($target['availability'] ?? null) ?? 'out_of_stock';
        $stock = isset($target['stock']) && is_numeric($target['stock']) ? (int) $target['stock'] : null;
        $policy = is_array($target['availability'] ?? null) ? ($target['availability']['policy'] ?? null) : null;
        $canFulfill = $token === 'in_stock'
            && ($stock === null || $policy === 'backorder' || $policy === 'unlimited' || $policy === 'not_tracked' || $stock >= $quantity);

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'check_product_availability',
            array_filter([
                'found' => true,
                'catalog_item_id' => $row['catalog_item_id'],
                'product_name' => $row['name'],
                'variant_id' => $selectedVariant['id'] ?? null,
                'variant_name' => $selectedVariant['name'] ?? null,
                'availability' => $token,
                'stock' => $stock,
                'policy' => $policy,
                'requested_quantity' => $quantity,
                'can_fulfill_quantity' => $canFulfill,
                'availability_message' => $row['availability_message'] ?? null,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }
}
