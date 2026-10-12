<?php

declare(strict_types=1);

namespace App\AI\Tools;

/**
 * Canonical Hasim tool definitions. Names and schemas stay aligned with the
 * existing Inbox catalog tools; write/risk flags are owned here.
 */
final class CanonicalTools
{
    public const SEARCH_PRODUCTS = 'search_products';

    public const GET_PRODUCT_INTELLIGENCE = 'get_product_intelligence';

    public const EVALUATE_PRODUCT_SUITABILITY = 'evaluate_product_suitability';

    public const SAVE_CUSTOMER_COMMERCE_CONTEXT = 'save_customer_commerce_context';

    /** @var list<string> */
    public const CATALOG_READ_SKILLS = ['customer_support', 'product_recommendation', 'product_search', 'sales'];

    /** @var list<string> */
    public const CATALOG_INTELLIGENCE_SKILLS = ['customer_support', 'product_recommendation', 'sales'];

    /** @var list<string> */
    public const CATALOG_RECOMMEND_SKILLS = ['product_recommendation', 'sales'];

    public static function searchProducts(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::SEARCH_PRODUCTS,
            description: 'Search active chat catalog products for the current workspace using a customer query.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_READ_SKILLS,
            inputSchema: [
                'query' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Customer product search query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Maximum number of products to return.',
                ],
                'attribute_filters' => [
                    'type' => 'object',
                    'required' => false,
                    'description' => 'Optional structured attribute filters.',
                ],
            ],
        );
    }

    public static function getProductIntelligence(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_PRODUCT_INTELLIGENCE,
            description: 'Retrieve the authoritative commerce intelligence profile for a chat catalog product.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_INTELLIGENCE_SKILLS,
            inputSchema: [
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
            ],
        );
    }

    public static function evaluateProductSuitability(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::EVALUATE_PRODUCT_SUITABILITY,
            description: 'Evaluate whether a chat catalog product is suitable using commerce intelligence and customer context.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_RECOMMEND_SKILLS,
            inputSchema: [
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
                    'description' => 'Customer-provided commerce context attributes for evaluation.',
                ],
            ],
        );
    }

    public static function saveCustomerCommerceContext(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::SAVE_CUSTOMER_COMMERCE_CONTEXT,
            description: 'Store explicit customer commerce context parameters for later recommendation evaluations.',
            category: 'catalog',
            access: ToolAccess::Write,
            risk: ToolRisk::Low,
            writes: true,
            allowedSkillKeys: self::CATALOG_RECOMMEND_SKILLS,
            inputSchema: [
                'context_attributes' => [
                    'type' => 'object',
                    'required' => true,
                    'description' => 'Key-value pairs of explicit customer context.',
                ],
            ],
        );
    }

    /**
     * @return list<ToolDefinition>
     */
    public static function all(): array
    {
        $definitions = [
            self::searchProducts(),
            self::getProductIntelligence(),
            self::evaluateProductSuitability(),
            self::saveCustomerCommerceContext(),
        ];
        usort($definitions, static fn (ToolDefinition $a, ToolDefinition $b): int => strcmp($a->name, $b->name));

        return $definitions;
    }
}
