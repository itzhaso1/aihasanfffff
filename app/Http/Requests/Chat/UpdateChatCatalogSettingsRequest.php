<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatCatalog;
use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChatCatalogSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('social_links_text')) {
            $links = preg_split('/\R/u', (string) $this->input('social_links_text')) ?: [];
            $this->merge([
                'social_links' => array_values(array_filter(array_map('trim', $links))),
            ]);
        }

        $schedule = $this->input('availability_schedule');
        if (! is_array($schedule)) {
            return;
        }

        foreach (['holidays', 'blackout_dates'] as $key) {
            $textKey = $key.'_text';
            if (! array_key_exists($textKey, $schedule)) {
                continue;
            }
            $dates = preg_split('/[\s,]+/u', (string) $schedule[$textKey]) ?: [];
            $schedule[$key] = array_values(array_filter(array_map('trim', $dates)));
            unset($schedule[$textKey]);
        }
        $this->merge(['availability_schedule' => $schedule]);
    }

    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null
            && $this->user() !== null
            && app(ChatCatalogAccess::class)->canManageSettings($this->user(), $workspace);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();
        $catalogId = $workspaceId
            ? ChatCatalog::withoutGlobalScopes()->where('workspace_id', $workspaceId)->value('id')
            : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('chat_catalogs', 'slug')->where(fn ($query) => $query->where('workspace_id', $workspaceId))
                    ->ignore($catalogId),
            ],
            'internal_name' => ['nullable', 'string', 'max:255'],
            'public_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:512'],
            'commerce_seller_id' => [
                'nullable',
                'integer',
                Rule::exists('commerce_sellers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'seller_public_display_name' => ['nullable', 'string', 'max:255'],
            'seller_support_contact' => ['nullable', 'array', 'max:5'],
            'seller_support_contact.email' => ['nullable', 'email:rfc,dns', 'max:255'],
            'seller_support_contact.phone' => ['nullable', 'string', 'max:50'],
            'seller_status_visibility' => ['nullable', 'boolean'],
            'default_language' => ['nullable', 'in:ar,en,bilingual'],
            'supported_languages' => ['nullable', 'array', 'min:1', 'max:10'],
            'supported_languages.*' => ['in:ar,en'],
            'default_locale' => ['nullable', 'in:ar_SA,ar_JO,ar_AE,en_US,en_GB'],
            'timezone' => ['nullable', 'timezone'],
            'fallback_language' => ['nullable', 'in:ar,en'],
            'rtl_mode' => ['nullable', 'boolean'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'logo_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,ico', 'max:4096'],
            'favicon_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,ico', 'max:2048'],
            'banner_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'brand_image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
            'remove_banner' => ['nullable', 'boolean'],
            'remove_brand_image' => ['nullable', 'boolean'],
            'favicon_path' => ['nullable', 'string', 'max:500'],
            'banner_path' => ['nullable', 'string', 'max:500'],
            'brand_image_path' => ['nullable', 'string', 'max:500'],
            'brand_description' => ['nullable', 'string', 'max:5000'],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'public_title' => ['nullable', 'string', 'max:255'],
            'public_description' => ['nullable', 'string', 'max:5000'],
            'public_support_contact' => ['nullable', 'array', 'max:5'],
            'public_support_contact.email' => ['nullable', 'email:rfc,dns', 'max:255'],
            'public_support_contact.phone' => ['nullable', 'string', 'max:50'],
            'public_website' => ['nullable', 'url', 'max:2048'],
            'social_links' => ['nullable', 'array', 'max:10'],
            'social_links.*' => ['nullable', 'url', 'max:2048'],
            'default_currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
            'sort_policy' => ['nullable', 'in:featured_first,newest,oldest,price_low_high,price_high_low,name_asc,name_desc,availability_first,manual'],
            'availability_mode' => ['nullable', 'in:always_open,scheduled,temporarily_unavailable,maintenance'],
            'unavailable_item_behavior' => ['nullable', 'in:hide,show_unavailable,show_disable_purchase,show_inquiry_only'],
            'display_settings' => ['nullable', 'array', 'max:20'],
            'display_settings.price_display' => ['nullable', 'in:standard,from_price'],
            'display_settings.decimal_places' => ['nullable', 'integer', 'between:0,2'],
            'display_settings.tax_inclusive_display' => ['nullable', 'boolean'],
            'display_settings.tax_exclusive_display' => ['nullable', 'boolean'],
            'display_settings.show_compare_at_price' => ['nullable', 'boolean'],
            'display_settings.show_sale_price' => ['nullable', 'boolean'],
            'display_settings.show_stock' => ['nullable', 'boolean'],
            'display_settings.show_sku' => ['nullable', 'boolean'],
            'display_settings.show_barcode' => ['nullable', 'boolean'],
            'display_settings.show_brand' => ['nullable', 'boolean'],
            'display_settings.show_variants' => ['nullable', 'boolean'],
            'discovery_settings' => ['nullable', 'array', 'max:20'],
            'discovery_settings.searchable' => ['nullable', 'boolean'],
            'discovery_settings.discoverable' => ['nullable', 'boolean'],
            'discovery_settings.featured_items_enabled' => ['nullable', 'boolean'],
            'discovery_settings.category_discovery' => ['nullable', 'boolean'],
            'discovery_settings.variant_discovery' => ['nullable', 'boolean'],
            'discovery_settings.sku_search' => ['nullable', 'boolean'],
            'discovery_settings.barcode_search' => ['nullable', 'boolean'],
            'discovery_settings.alias_search' => ['nullable', 'boolean'],
            'discovery_settings.synonym_search' => ['nullable', 'boolean'],
            'discovery_settings.description_search' => ['nullable', 'boolean'],
            'discovery_settings.tag_search' => ['nullable', 'boolean'],
            'discovery_settings.brand_search' => ['nullable', 'boolean'],
            'channel_visibility' => ['nullable', 'array', 'max:5'],
            'channel_visibility.chat' => ['nullable', 'boolean'],
            'channel_visibility.whatsapp' => ['nullable', 'boolean'],
            'channel_visibility.instagram' => ['nullable', 'boolean'],
            'channel_visibility.web' => ['nullable', 'boolean'],
            'channel_visibility.marketplace' => ['nullable', 'boolean'],
            'customer_service_policy' => ['nullable', 'array', 'max:20'],
            'customer_service_policy.inquiry_mode' => ['nullable', 'in:enabled,inquiry_only,disabled'],
            'customer_service_policy.response_expectation_hours' => ['nullable', 'integer', 'between:1,720'],
            'customer_service_policy.out_of_hours_message' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.unavailable_product_message' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.unavailable_catalog_message' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.backorder_message' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.low_stock_message' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.digital_delivery_notice' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.payment_required_notice' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.support_contact_instructions' => ['nullable', 'string', 'max:1000'],
            'customer_service_policy.paused_discovery_behavior' => ['nullable', 'in:hide,show_unavailable'],
            'availability_schedule' => ['nullable', 'array', 'max:10'],
            'availability_schedule.enabled' => ['nullable', 'boolean'],
            'availability_schedule.weekly' => ['nullable', 'array', 'max:7'],
            'availability_schedule.weekly.*.day' => ['required_with:availability_schedule.weekly', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'availability_schedule.weekly.*.closed' => ['nullable', 'boolean'],
            'availability_schedule.weekly.*.start' => ['nullable', 'date_format:H:i'],
            'availability_schedule.weekly.*.end' => ['nullable', 'date_format:H:i'],
            'availability_schedule.holidays' => ['nullable', 'array', 'max:366'],
            'availability_schedule.holidays.*' => ['date_format:Y-m-d'],
            'availability_schedule.blackout_dates' => ['nullable', 'array', 'max:366'],
            'availability_schedule.blackout_dates.*' => ['date_format:Y-m-d'],
            'availability_schedule.custom_closed_message' => ['nullable', 'string', 'max:1000'],
            'customer_templates' => ['nullable', 'array', 'max:10'],
            'customer_templates.*' => ['array', 'max:2'],
            'customer_templates.*.ar' => ['nullable', 'string', 'max:1000'],
            'customer_templates.*.en' => ['nullable', 'string', 'max:1000'],
            'seo' => ['nullable', 'array', 'max:10'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.canonical_slug' => ['nullable', 'string', 'max:255'],
            'seo.robots' => ['nullable', Rule::in(['index,follow', 'noindex', 'nofollow'])],
            'seo.share_title' => ['nullable', 'string', 'max:255'],
            'seo.share_description' => ['nullable', 'string', 'max:500'],
            'seo.share_image' => ['nullable', 'string', 'max:500'],
            'search_weights' => ['nullable', 'array', 'max:7'],
            'search_weights.exact_name' => ['nullable', 'integer', 'between:1,10'],
            'search_weights.sku' => ['nullable', 'integer', 'between:1,10'],
            'search_weights.barcode' => ['nullable', 'integer', 'between:1,10'],
            'search_weights.alias' => ['nullable', 'integer', 'between:1,10'],
            'search_weights.category' => ['nullable', 'integer', 'between:1,10'],
            'search_weights.tag' => ['nullable', 'integer', 'between:1,10'],
            'search_weights.description' => ['nullable', 'integer', 'between:1,10'],
        ];
    }
}
