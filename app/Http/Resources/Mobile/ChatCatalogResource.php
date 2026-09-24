<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatCatalog */
class ChatCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'internal_name' => $this->internal_name,
            'public_name' => $this->public_name,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'status' => $this->status?->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'commerce_seller_id' => $this->commerce_seller_id,
            'seller_public_display_name' => $this->seller_public_display_name,
            'seller_support_contact' => $this->seller_support_contact ?? [],
            'seller_status_visibility' => (bool) $this->seller_status_visibility,
            'default_language' => $this->default_language,
            'supported_languages' => $this->supported_languages ?? [],
            'default_locale' => $this->default_locale,
            'timezone' => $this->timezone,
            'fallback_language' => $this->fallback_language,
            'rtl_mode' => (bool) $this->rtl_mode,
            'logo_path' => $this->logo_path,
            'favicon_path' => $this->favicon_path,
            'banner_path' => $this->banner_path,
            'brand_image_path' => $this->brand_image_path,
            'brand_description' => $this->brand_description,
            'legal_business_name' => $this->legal_business_name,
            'public_title' => $this->public_title,
            'public_description' => $this->public_description,
            'public_support_contact' => $this->public_support_contact ?? [],
            'public_website' => $this->public_website,
            'social_links' => $this->social_links ?? [],
            'default_currency' => $this->default_currency,
            'sort_policy' => $this->sort_policy?->value,
            'availability_mode' => $this->availability_mode?->value,
            'unavailable_item_behavior' => $this->unavailable_item_behavior?->value,
            'display_settings' => $this->display_settings ?? [],
            'discovery_settings' => $this->discovery_settings ?? [],
            'channel_visibility' => $this->channel_visibility ?? [],
            'customer_service_policy' => $this->customer_service_policy ?? [],
            'availability_schedule' => $this->availability_schedule ?? [],
            'customer_templates' => $this->customer_templates ?? [],
            'seo' => $this->seo ?? [],
            'search_weights' => $this->search_weights ?? [],
            'communication_profile' => $this->whenLoaded('communicationProfile', fn () => [
                'primary_language' => $this->communicationProfile?->primary_language,
                'supported_languages' => $this->communicationProfile?->supported_languages ?? [],
                'arabic_dialect' => $this->communicationProfile?->arabic_dialect?->value,
                'formality' => $this->communicationProfile?->formality?->value,
                'communication_style' => $this->communicationProfile?->communication_style,
                'greeting_behavior' => $this->communicationProfile?->greeting_behavior,
                'name_usage' => $this->communicationProfile?->name_usage,
                'language_fallback' => $this->communicationProfile?->language_fallback,
                'date_format' => $this->communicationProfile?->date_format,
                'time_format' => $this->communicationProfile?->time_format,
                'number_format' => $this->communicationProfile?->number_format,
                'currency_format' => $this->communicationProfile?->currency_format,
                'units' => $this->communicationProfile?->units,
                'response_length' => $this->communicationProfile?->response_length,
                'punctuation_style' => $this->communicationProfile?->punctuation_style,
                'emoji_policy' => $this->communicationProfile?->emoji_policy,
            ]),
        ];
    }
}
