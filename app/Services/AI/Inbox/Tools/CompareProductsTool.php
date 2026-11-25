<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Chat\ChatCatalogRetrievalService;

/**
 * Side-by-side comparison of 2 to 4 workspace catalog items.
 */
final class CompareProductsTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function name(): string
    {
        return 'compare_products';
    }

    public function description(): string
    {
        return 'Compare 2 or more catalog products side-by-side on price, active offers, stock, category, specifications, and variants.';
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
            'first_item_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'First catalog item ID.',
            ],
            'second_item_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Second catalog item ID.',
            ],
            'first_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'First product name if ID is not known.',
            ],
            'second_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Second product name if ID is not known.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $ids = array_values(array_filter([
            isset($arguments['first_item_id']) && is_numeric($arguments['first_item_id']) ? (int) $arguments['first_item_id'] : null,
            isset($arguments['second_item_id']) && is_numeric($arguments['second_item_id']) ? (int) $arguments['second_item_id'] : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0));

        $names = array_values(array_filter([
            isset($arguments['first_name']) && is_string($arguments['first_name']) ? trim($arguments['first_name']) : null,
            isset($arguments['second_name']) && is_string($arguments['second_name']) ? trim($arguments['second_name']) : null,
        ], static fn (?string $name): bool => $name !== null && $name !== ''));

        if (count($ids) + count($names) < 2) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'compare_products',
                'invalid_arguments',
                'Provide at least two product IDs or names to compare.',
            );
        }

        $items = $this->catalogRetrieval->compareItemsForAi($context->workspace, $ids, $names);

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'compare_products',
            [
                'count' => count($items),
                'products' => $items,
            ],
        );
    }
}
