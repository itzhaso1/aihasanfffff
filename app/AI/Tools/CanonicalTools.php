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

    public const CREATE_CUSTOMER_ORDER = 'create_customer_order';

    public const SEND_PRODUCT_IMAGE = 'send_product_image';

    public const GET_PRODUCT_DETAILS = 'get_product_details';

    public const GET_PRODUCT_VARIANTS = 'get_product_variants';

    public const CHECK_PRODUCT_AVAILABILITY = 'check_product_availability';

    public const GET_CURRENT_PRICE = 'get_current_price';

    public const GET_ACTIVE_OFFERS = 'get_active_offers';

    public const COMPARE_PRODUCTS = 'compare_products';

    public const GET_SHIPPING_INFORMATION = 'get_shipping_information';

    public const GET_STORE_INFORMATION = 'get_store_information';

    public const GET_ORDER_STATUS = 'get_order_status';

    public const TRANSFER_TO_HUMAN_AGENT = 'transfer_to_human_agent';

    /** @var list<string> */
    public const ORDER_CAPTURE_SKILLS = ['customer_support', 'sales', 'booking_agent', 'product_recommendation', 'order_handling'];

    /** @var list<string> */
    public const CATALOG_READ_SKILLS = ['customer_support', 'product_recommendation', 'product_search', 'sales', 'booking_agent'];

    /** @var list<string> */
    public const CATALOG_DETAIL_SKILLS = ['customer_support', 'product_recommendation', 'sales', 'booking_agent'];

    /** @var list<string> */
    public const STORE_INFO_SKILLS = ['customer_support', 'complaint_handling', 'sales', 'booking_agent', 'product_recommendation'];

    /** @var list<string> */
    public const HANDOFF_SKILLS = ['customer_support', 'complaint_handling', 'human_handoff', 'sales'];

    /** @var list<string> */
    public const CATALOG_IMAGE_SKILLS = ['customer_support', 'product_recommendation', 'product_search', 'sales', 'booking_agent'];

    /** @var list<string> */
    public const CATALOG_INTELLIGENCE_SKILLS = ['customer_support', 'product_recommendation', 'sales'];

    /** @var list<string> */
    public const CATALOG_RECOMMEND_SKILLS = ['product_recommendation', 'sales'];

    public static function searchProducts(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::SEARCH_PRODUCTS,
            description: 'Search this workspace\'s products and inventory. Unpublished storefront catalog settings must not block the search.',
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
                'category' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Optional category filter.',
                ],
                'brand' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Optional brand filter.',
                ],
                'in_stock_only' => [
                    'type' => 'boolean',
                    'required' => false,
                    'description' => 'Only return in-stock items.',
                ],
                'on_sale_only' => [
                    'type' => 'boolean',
                    'required' => false,
                    'description' => 'Only return discounted items.',
                ],
                'min_price' => [
                    'type' => 'number',
                    'required' => false,
                    'description' => 'Optional minimum unit price.',
                ],
                'max_price' => [
                    'type' => 'number',
                    'required' => false,
                    'description' => 'Optional maximum unit price.',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Sort order: relevance, price_asc, price_desc, discount_desc, recommended, newest.',
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

    public static function createCustomerOrder(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::CREATE_CUSTOMER_ORDER,
            description: 'Record customer details and create or update customer order when customer wants to buy, order, or book items. De-duplicates by phone.',
            category: 'order',
            access: ToolAccess::Write,
            risk: ToolRisk::Low,
            writes: true,
            allowedSkillKeys: self::ORDER_CAPTURE_SKILLS,
            inputSchema: [
                'customer_name' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Customer name.',
                ],
                'phone' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Customer phone or WhatsApp number.',
                ],
                'items' => [
                    'type' => 'array',
                    'required' => true,
                    'description' => 'Array of items/products with name, id, quantity.',
                ],
                'notes' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Customer notes, delivery address or instructions.',
                ],
                'address' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Delivery address.',
                ],
                'is_complete' => [
                    'type' => 'boolean',
                    'required' => false,
                    'description' => 'True if info is complete, false if incomplete and needs review.',
                ],
                'review_reason' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Reason if human review is needed.',
                ],
                'required_action' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Action required from the store agent.',
                ],
            ],
        );
    }

    public static function sendProductImage(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::SEND_PRODUCT_IMAGE,
            description: 'Send the official product image/photo to the customer in the chat when they ask to see a picture or photo of a product. If the item has an image, it will be attached and sent to the conversation. If no image exists for the product, the tool reports that no image is available.',
            category: 'catalog',
            access: ToolAccess::Write,
            risk: ToolRisk::Low,
            writes: true,
            allowedSkillKeys: self::CATALOG_IMAGE_SKILLS,
            inputSchema: [
                'item_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Catalog item ID to send image for.',
                ],
                'product_name' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Product name or search keyword if item ID is not known.',
                ],
            ],
        );
    }

    public static function getProductDetails(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_PRODUCT_DETAILS,
            description: 'Retrieve complete details, specifications, pricing, active discounts, stock, policies, and variants for a specific catalog product.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_DETAIL_SKILLS,
            inputSchema: [
                'item_id' => ['type' => 'integer', 'required' => false, 'description' => 'Catalog item ID.'],
                'product_name' => ['type' => 'string', 'required' => false, 'description' => 'Product name if item_id is not known.'],
                'sku' => ['type' => 'string', 'required' => false, 'description' => 'Product or variant SKU.'],
            ],
        );
    }

    public static function getProductVariants(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_PRODUCT_VARIANTS,
            description: 'List all active variants (sizes, colors, capacities, editions) for a catalog item with individual price and stock.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_DETAIL_SKILLS,
            inputSchema: [
                'item_id' => ['type' => 'integer', 'required' => false, 'description' => 'Catalog item ID.'],
                'product_name' => ['type' => 'string', 'required' => false, 'description' => 'Product name if item_id is not known.'],
            ],
        );
    }

    public static function checkProductAvailability(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::CHECK_PRODUCT_AVAILABILITY,
            description: 'Check authoritative stock status and fulfillable quantity for a product or specific variant.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_DETAIL_SKILLS,
            inputSchema: [
                'item_id' => ['type' => 'integer', 'required' => false, 'description' => 'Catalog item ID.'],
                'product_name' => ['type' => 'string', 'required' => false, 'description' => 'Product name if item_id is not known.'],
                'variant_id' => ['type' => 'integer', 'required' => false, 'description' => 'Optional specific variant ID.'],
                'quantity' => ['type' => 'integer', 'required' => false, 'description' => 'Optional requested quantity.'],
            ],
        );
    }

    public static function getCurrentPrice(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_CURRENT_PRICE,
            description: 'Get the authoritative current price, base price, active promotional/sale price, and currency for a product or variant.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_DETAIL_SKILLS,
            inputSchema: [
                'item_id' => ['type' => 'integer', 'required' => false, 'description' => 'Catalog item ID.'],
                'product_name' => ['type' => 'string', 'required' => false, 'description' => 'Product name if item_id is not known.'],
                'variant_id' => ['type' => 'integer', 'required' => false, 'description' => 'Optional specific variant ID.'],
            ],
        );
    }

    public static function getActiveOffers(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_ACTIVE_OFFERS,
            description: 'Retrieve active product discounts and promotional offers in this workspace, sorted by highest discount percentage.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_DETAIL_SKILLS,
            inputSchema: [
                'query' => ['type' => 'string', 'required' => false, 'description' => 'Optional keyword filter.'],
                'category' => ['type' => 'string', 'required' => false, 'description' => 'Optional category filter.'],
                'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum offers to return.'],
            ],
        );
    }

    public static function compareProducts(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::COMPARE_PRODUCTS,
            description: 'Compare 2 or more catalog products side-by-side on price, active offers, stock, specifications, and variants.',
            category: 'catalog',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::CATALOG_DETAIL_SKILLS,
            inputSchema: [
                'first_item_id' => ['type' => 'integer', 'required' => false, 'description' => 'First catalog item ID.'],
                'second_item_id' => ['type' => 'integer', 'required' => false, 'description' => 'Second catalog item ID.'],
                'first_name' => ['type' => 'string', 'required' => false, 'description' => 'First product name if ID is not known.'],
                'second_name' => ['type' => 'string', 'required' => false, 'description' => 'Second product name if ID is not known.'],
            ],
        );
    }

    public static function getShippingInformation(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_SHIPPING_INFORMATION,
            description: 'Retrieve verified shipping and delivery policies, costs, timeframes, or digital delivery notes for this store.',
            category: 'store',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::STORE_INFO_SKILLS,
            inputSchema: [
                'city' => ['type' => 'string', 'required' => false, 'description' => 'Optional destination city.'],
            ],
        );
    }

    public static function getStoreInformation(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_STORE_INFORMATION,
            description: 'Retrieve verified store working hours, contact options, payment methods, return/warranty policies, and location.',
            category: 'store',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::STORE_INFO_SKILLS,
            inputSchema: [
                'topic' => ['type' => 'string', 'required' => false, 'description' => 'One of: all, hours, contact, policies, returns, payment, location.'],
            ],
        );
    }

    public static function getOrderStatus(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::GET_ORDER_STATUS,
            description: 'Look up order status belonging strictly to the current conversation customer.',
            category: 'order',
            access: ToolAccess::Read,
            risk: ToolRisk::Read,
            allowedSkillKeys: self::ORDER_CAPTURE_SKILLS,
            inputSchema: [
                'order_number' => ['type' => 'string', 'required' => false, 'description' => 'Optional order number.'],
            ],
        );
    }

    public static function transferToHumanAgent(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::TRANSFER_TO_HUMAN_AGENT,
            description: 'Escalate and transfer the conversation to a human agent when requested by the customer or when human review is required.',
            category: 'handoff',
            access: ToolAccess::Write,
            risk: ToolRisk::Low,
            writes: true,
            maxCallsPerRun: 1,
            allowedSkillKeys: self::HANDOFF_SKILLS,
            inputSchema: [
                'reason' => ['type' => 'string', 'required' => false, 'description' => 'Summary reason for human escalation.'],
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
            self::getProductDetails(),
            self::getProductVariants(),
            self::checkProductAvailability(),
            self::getCurrentPrice(),
            self::getActiveOffers(),
            self::compareProducts(),
            self::getShippingInformation(),
            self::getStoreInformation(),
            self::getOrderStatus(),
            self::transferToHumanAgent(),
            self::getProductIntelligence(),
            self::evaluateProductSuitability(),
            self::saveCustomerCommerceContext(),
            self::createCustomerOrder(),
            self::sendProductImage(),
        ];
        usort($definitions, static fn (ToolDefinition $a, ToolDefinition $b): int => strcmp($a->name, $b->name));

        return $definitions;
    }
}
