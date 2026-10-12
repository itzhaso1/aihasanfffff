<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceEvaluationStatus;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;

final class CommerceRecommendationEvaluator
{
    public function __construct(
        private readonly CommerceProductIntelligenceService $productIntelligenceService,
        private readonly CommerceCompatibilityService $compatibilityService,
        private readonly CommerceRuleService $ruleService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function evaluate(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        array $customerContext,
    ): CommerceRecommendationEvaluation {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $item, $variant, $customerContext): CommerceRecommendationEvaluation {
            $intelligenceProfile = $this->productIntelligenceService->getIntelligenceProfile($workspace, $item, $variant);

            $missingRequirements = [];
            $incompatibilityReasons = [];
            $warnings = [];
            $evaluatedAttributes = [];
            $provenanceSummary = [
                'merchant_authoritative_count' => 0,
                'customer_context_keys_evaluated' => array_keys($customerContext),
            ];

            // 1. Evaluate Requirements
            foreach ($intelligenceProfile->requirements as $req) {
                $key = $req['key'];
                if ($req['is_required']) {
                    if (! array_key_exists($key, $customerContext) || $customerContext[$key] === null || $customerContext[$key] === '') {
                        $missingRequirements[] = "Required context '{$req['label']}' ({$key}) is missing.";
                    }
                }
            }

            // 2. Evaluate Attributes & Authoritative Count
            foreach ($intelligenceProfile->attributes as $attrKey => $attrData) {
                $evaluatedAttributes[$attrKey] = $attrData['value'];
                if (in_array($attrData['source'], ['merchant_provided', 'merchant_rule'], true)) {
                    $provenanceSummary['merchant_authoritative_count']++;
                }
            }

            // 3. Evaluate Compatibility (if compatibility rules exist for the item)
            $compatResult = $this->compatibilityService->evaluateCompatibility(
                $workspace,
                'chat_catalog_item',
                (int) $item->id,
                $customerContext,
            );

            if ($compatResult['has_compatibility_data']) {
                if ($compatResult['is_compatible'] === false) {
                    $incompatibilityReasons[] = 'Item is not compatible with the provided customer context reference.';
                } elseif ($compatResult['compatibility_type'] === 'requires_adapter') {
                    $warnings[] = 'Item requires an adapter or conversion module for compatibility.';
                } elseif ($compatResult['compatibility_type'] === 'conditional') {
                    $warnings[] = 'Item compatibility is conditional on specific setup or environment.';
                }
            }

            // 4. Evaluate Intelligence Rules
            $ruleContext = array_merge($customerContext, $evaluatedAttributes);
            $activeRules = $this->ruleService->listActiveRules(
                $workspace,
                categoryId: $item->chat_catalog_category_id ? (int) $item->chat_catalog_category_id : null,
                entityId: (int) $item->id,
            );
            $ruleEval = $this->ruleService->evaluateRules($activeRules, $ruleContext);

            foreach ($ruleEval['messages'] as $msg) {
                $warnings[] = $msg;
            }

            foreach ($ruleEval['triggered_rules'] as $rule) {
                if ($rule->rule_type->value === 'incompatibility') {
                    $incompatibilityReasons[] = $rule->actions['message'] ?? "Incompatibility rule triggered: {$rule->name}";
                } elseif ($rule->rule_type->value === 'required_attribute') {
                    $reqKey = $rule->actions['required_key'] ?? 'unknown';
                    if (! isset($customerContext[$reqKey])) {
                        $missingRequirements[] = $rule->actions['message'] ?? "Rule requires context key '{$reqKey}'.";
                    }
                }
            }

            // 5. Determine Overall Evaluation Status
            if ($incompatibilityReasons !== []) {
                $status = CommerceEvaluationStatus::Incompatible;
            } elseif ($missingRequirements !== []) {
                $status = CommerceEvaluationStatus::InsufficientInformation;
            } elseif ($warnings !== []) {
                $status = CommerceEvaluationStatus::ConditionallySuitable;
            } else {
                $status = CommerceEvaluationStatus::Compatible;
            }

            return new CommerceRecommendationEvaluation(
                itemId: (int) $item->id,
                variantId: $variant?->id ? (int) $variant->id : null,
                status: $status,
                missingRequirements: array_values(array_unique($missingRequirements)),
                incompatibilityReasons: array_values(array_unique($incompatibilityReasons)),
                warnings: array_values(array_unique($warnings)),
                evaluatedAttributes: $evaluatedAttributes,
                provenanceSummary: $provenanceSummary,
            );
        });
    }
}
