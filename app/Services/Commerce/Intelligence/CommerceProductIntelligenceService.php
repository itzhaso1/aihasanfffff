<?php

namespace App\Services\Commerce\Intelligence;

use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use RuntimeException;

final class CommerceProductIntelligenceService
{
    public function __construct(
        private readonly CommerceAttributeService $attributes,
        private readonly CommerceRequirementService $requirements,
        private readonly CommerceCompatibilityService $compatibilities,
        private readonly CommerceRuleService $rules,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function getIntelligenceProfile(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant = null,
    ): CommerceProductIntelligenceProfile {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $item, $variant): CommerceProductIntelligenceProfile {
            if ((int) $item->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Catalog item workspace mismatch.');
            }
            if ($variant && (int) $variant->workspace_id !== (int) $workspace->id) {
                throw new RuntimeException('Catalog variant workspace mismatch.');
            }

            $item->loadMissing(['category', 'structuredAttributes']);
            if ($variant) {
                $variant->loadMissing('structuredAttributes');
            }

            // 1. Gather intelligence attributes
            $attrValues = $this->attributes->getEntityAttributeValues($workspace, 'chat_catalog_item', (int) $item->id);
            $variantAttrValues = $variant
                ? $this->attributes->getEntityAttributeValues($workspace, 'chat_catalog_item_variant', (int) $variant->id)
                : collect();

            $formattedAttributes = [];
            foreach ($attrValues as $val) {
                if ($val->definition && $val->definition->is_active) {
                    $formattedAttributes[$val->definition->key] = [
                        'key' => $val->definition->key,
                        'display_name' => $val->definition->display_name,
                        'data_type' => $val->definition->data_type->value,
                        'value' => $val->rawTypedValue(),
                        'unit' => $val->unit ?? $val->definition->unit,
                        'source' => $val->source->value,
                        'is_required' => $val->definition->is_required,
                        'is_searchable' => $val->definition->is_searchable,
                    ];
                }
            }

            foreach ($variantAttrValues as $val) {
                if ($val->definition && $val->definition->is_active) {
                    $formattedAttributes[$val->definition->key] = [
                        'key' => $val->definition->key,
                        'display_name' => $val->definition->display_name,
                        'data_type' => $val->definition->data_type->value,
                        'value' => $val->rawTypedValue(),
                        'unit' => $val->unit ?? $val->definition->unit,
                        'source' => $val->source->value,
                        'is_required' => $val->definition->is_required,
                        'is_searchable' => $val->definition->is_searchable,
                    ];
                }
            }

            // Fallback enrichment from ChatCatalogItemAttribute table if key not already set
            foreach ($item->structuredAttributes as $legacyAttr) {
                if (! isset($formattedAttributes[$legacyAttr->attribute_key])) {
                    $formattedAttributes[$legacyAttr->attribute_key] = [
                        'key' => $legacyAttr->attribute_key,
                        'display_name' => ucwords(str_replace('_', ' ', $legacyAttr->attribute_key)),
                        'data_type' => $legacyAttr->value_type ?? 'string',
                        'value' => $legacyAttr->attribute_value,
                        'unit' => null,
                        'source' => 'merchant_provided',
                        'is_required' => false,
                        'is_searchable' => true,
                    ];
                }
            }

            // 2. Gather active requirements
            $activeReqs = $this->requirements->listActiveRequirements(
                $workspace,
                categoryId: $item->chat_catalog_category_id ? (int) $item->chat_catalog_category_id : null,
                itemId: (int) $item->id,
            );
            $formattedReqs = $activeReqs->map(fn ($r) => [
                'key' => $r->key,
                'label' => $r->label,
                'data_type' => $r->data_type->value,
                'unit' => $r->unit,
                'is_required' => $r->is_required,
                'validation_rules' => $r->validation_rules,
            ])->values()->all();

            // 3. Gather compatibilities
            $compats = $this->compatibilities->listCompatibilitiesForEntity($workspace, 'chat_catalog_item', (int) $item->id);
            $formattedCompats = $compats->map(fn ($c) => [
                'id' => $c->id,
                'target_type' => $c->target_type,
                'target_entity_type' => $c->target_entity_type,
                'target_entity_id' => $c->target_entity_id,
                'target_reference' => $c->target_reference,
                'compatibility_type' => $c->compatibility_type->value,
                'provenance' => $c->provenance->value,
            ])->values()->all();

            // 4. Gather intelligence rules
            $activeRules = $this->rules->listActiveRules(
                $workspace,
                categoryId: $item->chat_catalog_category_id ? (int) $item->chat_catalog_category_id : null,
                entityId: (int) $item->id,
            );
            $formattedRules = $activeRules->map(fn ($rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'rule_type' => $rule->rule_type->value,
                'conditions' => $rule->conditions,
                'actions' => $rule->actions,
            ])->values()->all();

            return new CommerceProductIntelligenceProfile(
                itemId: (int) $item->id,
                variantId: $variant?->id ? (int) $variant->id : null,
                itemName: $item->name,
                variantName: $variant?->name,
                categoryName: $item->category?->name,
                attributes: $formattedAttributes,
                requirements: $formattedReqs,
                compatibilities: $formattedCompats,
                rules: $formattedRules,
                metadata: [
                    'classification' => $item->classification?->value,
                    'inventory_policy' => $item->inventory_policy?->value,
                    'has_digital_profile' => (bool) $item->digitalProfile,
                ],
            );
        });
    }
}
