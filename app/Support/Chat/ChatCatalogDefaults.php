<?php

namespace App\Support\Chat;

use App\Enums\ChatCatalog\ChatCatalogArabicDialect;
use App\Enums\ChatCatalog\ChatCatalogAvailabilityMode;
use App\Enums\ChatCatalog\ChatCatalogFormality;
use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Enums\ChatCatalog\ChatCatalogSortPolicy;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;

/**
 * Typed defaults for Chat Catalog v2 settings (configuration matrix source of truth).
 */
final class ChatCatalogDefaults
{
    public const DEFAULT_CURRENCY = 'SAR';

    public const DEFAULT_LANGUAGE = 'ar';

    public const DEFAULT_LOCALE = 'ar_SA';

    public const DEFAULT_TIMEZONE = 'Asia/Riyadh';

    /**
     * @return array<string, mixed>
     */
    public static function catalogRoot(int $workspaceId, string $name): array
    {
        return [
            'workspace_id' => $workspaceId,
            'name' => $name,
            'slug' => 'catalog',
            'internal_name' => $name,
            'public_name' => $name,
            'status' => ChatCatalogOperatingStatus::Draft,
            'default_language' => self::DEFAULT_LANGUAGE,
            'supported_languages' => [self::DEFAULT_LANGUAGE, 'en'],
            'default_locale' => self::DEFAULT_LOCALE,
            'timezone' => self::DEFAULT_TIMEZONE,
            'fallback_language' => 'en',
            'rtl_mode' => true,
            'default_currency' => self::DEFAULT_CURRENCY,
            'sort_policy' => ChatCatalogSortPolicy::Manual,
            'availability_mode' => ChatCatalogAvailabilityMode::AlwaysOpen,
            'unavailable_item_behavior' => ChatCatalogUnavailableBehavior::ShowUnavailable,
            'display_settings' => self::displaySettings(),
            'discovery_settings' => self::discoverySettings(),
            'channel_visibility' => self::channelVisibility(),
            'customer_service_policy' => self::customerServicePolicy(),
            'availability_schedule' => self::availabilitySchedule(),
            'customer_templates' => self::customerTemplates(),
            'seo' => [],
            'search_weights' => self::searchWeights(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function communicationProfile(): array
    {
        return [
            'primary_language' => self::DEFAULT_LANGUAGE,
            'supported_languages' => [self::DEFAULT_LANGUAGE, 'en'],
            'arabic_dialect' => ChatCatalogArabicDialect::Msa,
            'formality' => ChatCatalogFormality::Neutral,
            'communication_style' => 'balanced',
            'greeting_behavior' => 'first_contact',
            'name_usage' => 'first_name',
            'language_fallback' => 'en',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'number_format' => 'western',
            'currency_format' => 'symbol_after',
            'units' => 'metric',
            'response_length' => 'balanced',
            'punctuation_style' => 'standard',
            'emoji_policy' => 'limited',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function displaySettings(): array
    {
        return [
            'price_display' => 'standard',
            'decimal_places' => 2,
            'tax_inclusive_display' => false,
            'tax_exclusive_display' => true,
            'show_compare_at_price' => true,
            'show_sale_price' => true,
            'show_stock' => true,
            'show_sku' => false,
            'show_barcode' => false,
            'show_brand' => true,
            'show_variants' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function discoverySettings(): array
    {
        return [
            'searchable' => true,
            'discoverable' => true,
            'featured_items_enabled' => true,
            'category_discovery' => true,
            'variant_discovery' => true,
            'sku_search' => true,
            'barcode_search' => true,
            'alias_search' => true,
            'synonym_search' => true,
            'description_search' => true,
            'tag_search' => true,
            'brand_search' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function channelVisibility(): array
    {
        return [
            'chat' => true,
            'whatsapp' => true,
            'instagram' => true,
            'web' => false,
            'marketplace' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerServicePolicy(): array
    {
        return [
            'inquiry_mode' => 'enabled',
            'response_expectation_hours' => 24,
            'out_of_hours_message' => null,
            'unavailable_product_message' => null,
            'unavailable_catalog_message' => null,
            'backorder_message' => null,
            'low_stock_message' => null,
            'digital_delivery_notice' => null,
            'payment_required_notice' => null,
            'support_contact_instructions' => null,
            // "hide" prevents paused catalogs from being searchable. The only
            // alternative exposes products as unavailable; it never enables
            // ordering because this catalog has no checkout workflow.
            'paused_discovery_behavior' => 'hide',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function availabilitySchedule(): array
    {
        return [
            'enabled' => true,
            'weekly' => [],
            'holidays' => [],
            'blackout_dates' => [],
            'custom_closed_message' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerTemplates(): array
    {
        return [
            'greeting' => ['ar' => null, 'en' => null],
            'unavailable' => ['ar' => null, 'en' => null],
            'out_of_stock' => ['ar' => null, 'en' => null],
            'low_stock' => ['ar' => null, 'en' => null],
            'digital_delivery_pending' => ['ar' => null, 'en' => null],
            'digital_delivery_ready' => ['ar' => null, 'en' => null],
            'business_closed' => ['ar' => null, 'en' => null],
            'support_contact' => ['ar' => null, 'en' => null],
            'order_followup' => ['ar' => null, 'en' => null],
            'inquiry_response' => ['ar' => null, 'en' => null],
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function searchWeights(): array
    {
        return [
            'exact_name' => 5,
            'sku' => 4,
            'barcode' => 4,
            'alias' => 3,
            'tag' => 2,
            'category' => 2,
            'description' => 1,
        ];
    }

    public static function defaultItemInventoryPolicy(): ChatCatalogInventoryPolicy
    {
        return ChatCatalogInventoryPolicy::FiniteStock;
    }
}
