<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogAvailabilityMode;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Enums\ChatCatalog\ChatCatalogSortPolicy;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;
use App\Models\Commerce\Seller;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'workspace_id',
    'commerce_seller_id',
    'seller_public_display_name',
    'seller_support_contact',
    'seller_status_visibility',
    'name',
    'slug',
    'internal_name',
    'public_name',
    'description',
    'short_description',
    'status',
    'published_at',
    'archived_at',
    'default_language',
    'supported_languages',
    'default_locale',
    'timezone',
    'fallback_language',
    'rtl_mode',
    'logo_path',
    'favicon_path',
    'banner_path',
    'brand_image_path',
    'brand_description',
    'legal_business_name',
    'public_title',
    'public_description',
    'public_support_contact',
    'public_website',
    'social_links',
    'default_currency',
    'sort_policy',
    'availability_mode',
    'unavailable_item_behavior',
    'display_settings',
    'discovery_settings',
    'channel_visibility',
    'customer_service_policy',
    'availability_schedule',
    'customer_templates',
    'seo',
    'search_weights',
])]
class ChatCatalog extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'status' => ChatCatalogOperatingStatus::class,
            'sort_policy' => ChatCatalogSortPolicy::class,
            'availability_mode' => ChatCatalogAvailabilityMode::class,
            'unavailable_item_behavior' => ChatCatalogUnavailableBehavior::class,
            'supported_languages' => 'array',
            'seller_support_contact' => 'array',
            'seller_status_visibility' => 'boolean',
            'rtl_mode' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'display_settings' => 'array',
            'discovery_settings' => 'array',
            'channel_visibility' => 'array',
            'customer_service_policy' => 'array',
            'availability_schedule' => 'array',
            'customer_templates' => 'array',
            'seo' => 'array',
            'search_weights' => 'array',
            'public_support_contact' => 'array',
            'social_links' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }

    public function communicationProfile(): HasOne
    {
        return $this->hasOne(ChatCatalogCommunicationProfile::class, 'chat_catalog_id');
    }

    public function allowsCustomerDiscovery(): bool
    {
        return $this->status === ChatCatalogOperatingStatus::Published;
    }

    public function pausedDiscoveryBehavior(): string
    {
        $policy = is_array($this->customer_service_policy)
            ? $this->customer_service_policy
            : [];

        return ($policy['paused_discovery_behavior'] ?? 'hide') === 'show_unavailable'
            ? 'show_unavailable'
            : 'hide';
    }

    public function channelEnabled(string $channel): bool
    {
        $map = is_array($this->channel_visibility) ? $this->channel_visibility : [];

        return (bool) ($map[$channel] ?? false);
    }

    public function discoveryEnabled(string $key): bool
    {
        $map = is_array($this->discovery_settings) ? $this->discovery_settings : [];

        return (bool) ($map[$key] ?? true);
    }
}
