<?php

namespace App\Http\Requests\Chat;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Http\Requests\Chat\Concerns\NormalizesCatalogBooleanInput;
use App\Models\ChatCatalogItem;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatCatalogItemRequest extends FormRequest
{
    use NormalizesCatalogBooleanInput;

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedCatalogBooleans();

        $attributes = $this->input('structured_attributes');
        if (is_array($attributes)) {
            $this->merge(['structured_attributes' => $this->normalizeStructuredAttributes($attributes)]);
        }

        $variants = $this->input('variants');
        if (is_array($variants)) {
            foreach ($variants as $index => $variant) {
                if (! is_array($variant) || ! is_array($variant['structured_attributes'] ?? null)) {
                    continue;
                }
                $variants[$index]['structured_attributes'] = $this->normalizeStructuredAttributes(
                    $variant['structured_attributes']
                );
            }
            $this->merge(['variants' => $variants]);
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<mixed>
     */
    private function normalizeStructuredAttributes(array $rows): array
    {
        return array_map(function (mixed $row): mixed {
            if (! is_array($row)) {
                return $row;
            }
            if (! array_key_exists('attribute_key', $row) && array_key_exists('key', $row)) {
                $row['attribute_key'] = $row['key'];
            }
            if (! array_key_exists('attribute_value', $row) && array_key_exists('value', $row)) {
                $row['attribute_value'] = $row['value'];
            }

            return $row;
        }, $rows);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', ChatCatalogItem::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:512'],
            'internal_name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'customer_description' => ['nullable', 'string', 'max:5000'],
            'usage_instructions' => ['nullable', 'string', 'max:5000'],
            'specifications' => ['nullable', 'array', 'max:50'],
            'highlights' => ['nullable', 'array', 'max:30'],
            'highlights.*' => ['string', 'max:500'],
            'warnings' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'item_kind' => ['nullable', 'in:product,service,digital'],
            'tangibility' => ['nullable', 'in:tangible,intangible'],
            'classification' => ['nullable', 'in:physical,digital,service'],
            'digital_subtype' => ['nullable', 'in:file,code,license,credential,account,subscription,digital_bundle,custom_digital'],
            'service_type' => ['nullable', 'in:consultation,appointment,booking,implementation,custom_service'],
            'price' => ['required', 'decimal:0,2', 'min:0'],
            'worker_min_price' => [
                'nullable',
                'decimal:0,2',
                'min:0',
            ],
            'worker_max_price' => ['nullable'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'tax_display_behavior' => ['nullable', 'in:tax_inclusive,tax_exclusive,tax_hidden_until_checkout,tax_downstream'],
            'price_valid_from' => ['nullable', 'date'],
            'price_valid_until' => ['nullable', 'date', 'after_or_equal:price_valid_from'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'inventory_policy' => ['nullable', Rule::in(ChatCatalogInventoryPolicy::selectableValues())],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_bestseller' => ['nullable', 'boolean'],
            'is_new' => ['nullable', 'boolean'],
            'is_recommended' => ['nullable', 'boolean'],
            'is_searchable' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'publication_status' => ['nullable', 'in:draft,published,archived'],
            'published_at' => ['nullable', 'date'],
            'archived_at' => ['nullable', 'date'],
            'product_template_id' => ['nullable', 'integer', 'exists:chat_catalog_product_templates,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image_path' => ['nullable', 'string', 'max:500'],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'gallery_files' => ['nullable', 'array', 'max:20'],
            'gallery_files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'attributes' => ['nullable', 'array', 'max:50'],
            'policies' => ['nullable', 'array:returnable,refundable,exchangeable,cancellation_allowed,cancellation_window,warranty_available,warranty_duration,terms_required,customer_confirmation_required,non_returnable,non_refundable,activation_deadline,access_duration,maximum_downloads,re_download_policy,access_revocation_policy,service_duration,location_type,delivery_mode,remote,onsite,provider_required,booking_required,lead_time,cancellation_policy'],
            'policies.returnable' => ['nullable', 'boolean'],
            'policies.refundable' => ['nullable', 'boolean'],
            'policies.exchangeable' => ['nullable', 'boolean'],
            'policies.cancellation_allowed' => ['nullable', 'boolean'],
            'policies.cancellation_window' => ['nullable', 'integer', 'min:0', 'max:87600'],
            'policies.warranty_available' => ['nullable', 'boolean'],
            'policies.warranty_duration' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'policies.terms_required' => ['nullable', 'boolean'],
            'policies.customer_confirmation_required' => ['nullable', 'boolean'],
            'policies.non_returnable' => ['nullable', 'boolean'],
            'policies.non_refundable' => ['nullable', 'boolean'],
            'policies.activation_deadline' => ['nullable', 'date'],
            'policies.access_duration' => ['nullable', 'integer', 'min:0', 'max:876000'],
            'policies.maximum_downloads' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'policies.re_download_policy' => ['nullable', 'in:allowed,not_allowed,until_expiry'],
            'policies.access_revocation_policy' => ['nullable', 'in:none,on_refund,on_chargeback,on_expiry'],
            'policies.service_duration' => ['nullable', 'integer', 'min:0', 'max:876000'],
            'policies.location_type' => ['nullable', 'in:remote,onsite,hybrid'],
            'policies.delivery_mode' => ['nullable', 'in:remote,onsite,hybrid'],
            'policies.remote' => ['nullable', 'boolean'],
            'policies.onsite' => ['nullable', 'boolean'],
            'policies.provider_required' => ['nullable', 'boolean'],
            'policies.booking_required' => ['nullable', 'boolean'],
            'policies.lead_time' => ['nullable', 'integer', 'min:0', 'max:876000'],
            'policies.cancellation_policy' => ['nullable', 'string', 'max:1000'],
            'ai_safe_profile' => ['nullable', 'array:customer_summary,selling_points,common_customer_questions,customer_terms,synonyms,prohibited_claims,supported_claims,pronunciation,language_variants'],
            'tags' => ['nullable', 'array', 'max:100'],
            'tags.*' => ['string', 'max:255'],
            'aliases' => ['nullable', 'array', 'max:100'],
            'aliases.*' => ['string', 'max:255'],
            'search_phrases' => ['nullable', 'array', 'max:100'],
            'search_phrases.*' => ['string', 'max:255'],
            'commerce_seller_id' => [
                'nullable',
                'integer',
                Rule::exists('commerce_sellers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'chat_catalog_category_id' => [
                'nullable',
                'integer',
                Rule::exists('chat_catalog_categories', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.price' => ['nullable', 'decimal:0,2', 'min:0'],
            'variants.*.currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'variants.*.status' => ['nullable', 'in:draft,published,archived'],
            'variants.*.is_visible' => ['nullable', 'boolean'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.inventory_policy' => ['nullable', Rule::in(ChatCatalogInventoryPolicy::selectableValues())],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.attributes' => ['nullable', 'array', 'max:50'],
            'variants.*.structured_attributes' => ['nullable', 'array', 'max:50'],
            'variants.*.structured_attributes.*.id' => ['nullable', 'integer'],
            'variants.*.structured_attributes.*.attribute_key' => ['required_with:variants.*.structured_attributes', 'string', 'max:128'],
            'variants.*.structured_attributes.*.attribute_value' => ['nullable', 'string', 'max:2000'],
            'variants.*.structured_attributes.*.value_type' => ['nullable', 'in:string,number,boolean,measurement'],
            'variants.*.structured_attributes.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'media' => ['nullable', 'array', 'max:30'],
            'media.*.id' => ['nullable', 'integer'],
            'media.*.kind' => ['nullable', 'in:primary,gallery,thumbnail,video_reference'],
            'media.*.path' => ['required_with:media', 'string', 'max:500'],
            'media.*.alt' => ['nullable', 'string', 'max:500'],
            'media.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'media.*.focal_metadata' => ['nullable', 'array', 'max:10'],
            'media.*.focal_metadata.x' => ['nullable', 'numeric', 'between:0,100'],
            'media.*.focal_metadata.y' => ['nullable', 'numeric', 'between:0,100'],
            'media.*.is_visible' => ['nullable', 'boolean'],
            'structured_attributes' => ['nullable', 'array', 'max:50'],
            'structured_attributes.*.id' => ['nullable', 'integer'],
            'structured_attributes.*.attribute_key' => ['required_with:structured_attributes', 'string', 'max:128'],
            'structured_attributes.*.attribute_value' => ['nullable', 'string', 'max:2000'],
            'structured_attributes.*.value_type' => ['nullable', 'in:string,number,boolean,measurement'],
            'structured_attributes.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
