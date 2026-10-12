<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Commerce\Intelligence\CommerceProductIntelligenceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox agent tool to retrieve authoritative Commerce Intelligence profile for a chat catalog product.
 */
final class GetProductIntelligenceTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CommerceProductIntelligenceService $productIntelligenceService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function name(): string
    {
        return 'get_product_intelligence';
    }

    public function description(): string
    {
        return 'Retrieve authoritative Commerce Intelligence profile (attributes, requirements, compatibilities, rules) for a chat catalog product.';
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
            'item_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Catalog item ID.',
            ],
            'variant_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional catalog item variant ID.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = (int) ($arguments['item_id'] ?? 0);
        $variantId = isset($arguments['variant_id']) ? (int) $arguments['variant_id'] : null;

        if ($itemId <= 0) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_product_intelligence',
                'invalid_arguments',
                'Valid product item_id is required.',
            );
        }

        try {
            return $this->workspaceContext->runFor($context->workspace, function () use ($context, $itemId, $variantId): InboxAgentToolExecutionResult {
                $item = ChatCatalogItem::query()
                    ->where('workspace_id', $context->workspace->id)
                    ->find($itemId);

                if (! $item) {
                    return InboxAgentToolExecutionResult::failure(
                        $this->name(),
                        'get_product_intelligence',
                        'item_not_found',
                        "Product with ID {$itemId} was not found in this workspace.",
                    );
                }

                $variant = null;
                if ($variantId !== null && $variantId > 0) {
                    $variant = ChatCatalogItemVariant::query()
                        ->where('workspace_id', $context->workspace->id)
                        ->where('chat_catalog_item_id', $item->id)
                        ->find($variantId);
                }

                $profile = $this->productIntelligenceService->getIntelligenceProfile($context->workspace, $item, $variant);

                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'get_product_intelligence',
                    [
                        'intelligence_profile' => $profile->toArray(),
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.get_product_intelligence_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'message' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_product_intelligence',
                'retrieval_failed',
                'Product intelligence retrieval is temporarily unavailable.',
            );
        }
    }
}
