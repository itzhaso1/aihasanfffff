<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Commerce\Intelligence\CommerceCustomerContextService;
use App\Services\Commerce\Intelligence\CommerceRecommendationEvaluator;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox agent tool to evaluate chat catalog product suitability and compatibility using Commerce Intelligence.
 */
final class EvaluateProductSuitabilityTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CommerceRecommendationEvaluator $evaluator,
        private readonly CommerceCustomerContextService $customerContextService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function name(): string
    {
        return 'evaluate_product_suitability';
    }

    public function description(): string
    {
        return 'Evaluate whether a chat catalog product is suitable or compatible for a customer based on authoritative Commerce Intelligence requirements, attributes, rules, and customer context.';
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
                'description' => 'Catalog item ID to evaluate.',
            ],
            'variant_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional catalog item variant ID to evaluate.',
            ],
            'customer_context' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Customer-provided commerce context attributes for evaluation (e.g., {"bust_cm": 90, "height_cm": 160, "vehicle_model": "Camry"}).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = (int) ($arguments['item_id'] ?? 0);
        $variantId = isset($arguments['variant_id']) ? (int) $arguments['variant_id'] : null;
        $inputContext = is_array($arguments['customer_context'] ?? null) ? $arguments['customer_context'] : [];

        if ($itemId <= 0) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'evaluate_product_suitability',
                'invalid_arguments',
                'Valid product item_id is required.',
            );
        }

        try {
            return $this->workspaceContext->runFor($context->workspace, function () use ($context, $itemId, $variantId, $inputContext): InboxAgentToolExecutionResult {
                $item = ChatCatalogItem::query()
                    ->where('workspace_id', $context->workspace->id)
                    ->find($itemId);

                if (! $item) {
                    return InboxAgentToolExecutionResult::failure(
                        $this->name(),
                        'evaluate_product_suitability',
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

                $customerId = $context->conversation->customer_id ? (int) $context->conversation->customer_id : null;
                $sessionId = (string) ($context->conversation->external_id ?? '');

                // 1. Fetch existing stored customer context
                $storedContext = $this->customerContextService->getContextAsArray(
                    $context->workspace,
                    $customerId,
                    $sessionId !== '' ? $sessionId : null,
                );

                // 2. Persist new explicit customer context input if provided
                foreach ($inputContext as $key => $val) {
                    if ($val !== null && $val !== '') {
                        $this->customerContextService->setContext(
                            workspace: $context->workspace,
                            customerId: $customerId,
                            sessionId: $sessionId !== '' ? $sessionId : null,
                            key: (string) $key,
                            value: $val,
                        );
                    }
                }

                // 3. Merge stored & input context
                $mergedContext = array_merge($storedContext, $inputContext);

                // 4. Evaluate suitability via deterministic Commerce Intelligence
                $evaluation = $this->evaluator->evaluate($context->workspace, $item, $variant, $mergedContext);

                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'evaluate_product_suitability',
                    [
                        'item_id' => (int) $item->id,
                        'item_name' => $item->name,
                        'variant_id' => $variant?->id ? (int) $variant->id : null,
                        'variant_name' => $variant?->name,
                        'evaluation' => $evaluation->toArray(),
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.evaluate_product_suitability_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'message' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'evaluate_product_suitability',
                'evaluation_failed',
                'Product suitability evaluation is temporarily unavailable.',
            );
        }
    }
}
