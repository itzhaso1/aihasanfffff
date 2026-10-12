<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalogItem;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ChatCatalogItem */
class ChatCatalogItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        if (is_string($this->image_path) && $this->image_path !== '') {
            $imageUrl = Storage::disk('public')->url($this->image_path);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'internal_name' => $this->internal_name,
            'sku' => $this->sku,
            'barcode' => null,
            'brand' => $this->brand,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'external_reference' => $this->external_reference,
            'customer_description' => $this->customer_description,
            'usage_instructions' => $this->usage_instructions,
            'specifications' => $this->specifications ?? [],
            'highlights' => $this->highlights ?? [],
            'warnings' => $this->warnings,
            'terms' => $this->terms,
            'item_kind' => $this->item_kind,
            'tangibility' => $this->tangibility,
            'classification' => $this->classification?->value,
            'digital_subtype' => $this->digital_subtype,
            'service_type' => $this->service_type,
            'price' => Money::of((string) $this->price),
            'worker_min_price' => $this->worker_min_price !== null
                ? Money::of((string) $this->worker_min_price)
                : null,
            'worker_max_price' => $this->worker_max_price !== null
                ? Money::of((string) $this->worker_max_price)
                : null,
            'compare_at_price' => null,
            'sale_price' => null,
            'currency' => $this->currency ?: 'SAR',
            'tax_display_behavior' => $this->tax_display_behavior,
            'price_valid_from' => $this->price_valid_from?->toIso8601String(),
            'price_valid_until' => $this->price_valid_until?->toIso8601String(),
            'stock' => $this->stock,
            'inventory_policy' => $this->inventory_policy?->value,
            'is_active' => (bool) $this->is_active,
            'is_featured' => (bool) $this->is_featured,
            'is_bestseller' => (bool) $this->is_bestseller,
            'is_new' => (bool) $this->is_new,
            'is_recommended' => (bool) $this->is_recommended,
            'is_searchable' => (bool) $this->is_searchable,
            'is_visible' => (bool) $this->is_visible,
            'publication_status' => $this->publication_status,
            'published_at' => $this->published_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'sort_order' => (int) $this->sort_order,
            'image_url' => $imageUrl,
            'attributes' => $this->attributes ?? [],
            'policies' => $this->policies ?? [],
            'ai_safe_profile' => $this->ai_safe_profile ?? [],
            'tags' => $this->tags ?? [],
            'aliases' => $this->aliases ?? [],
            'search_phrases' => $this->search_phrases ?? [],
            'commerce_seller_id' => $this->commerce_seller_id,
            'media' => $this->whenLoaded('media', fn () => $this->media
                ->where('is_visible', true)
                ->map(fn ($media) => [
                    'id' => $media->id,
                    'kind' => $media->kind,
                    'path' => $media->path,
                    'url' => is_string($media->path) && $media->path !== ''
                        ? Storage::disk('public')->url($media->path)
                        : null,
                    'alt' => $media->alt,
                    'sort_order' => $media->sort_order,
                    'focal_metadata' => $media->focal_metadata ?? [],
                ])->values()->all()),
            'structured_attributes' => $this->whenLoaded('structuredAttributes', fn () => $this->structuredAttributes
                ->whereNull('chat_catalog_item_variant_id')
                ->map(fn ($attribute) => [
                    'id' => $attribute->id,
                    'key' => $attribute->attribute_key,
                    'attribute_key' => $attribute->attribute_key,
                    'value' => $attribute->attribute_value,
                    'attribute_value' => $attribute->attribute_value,
                    'value_type' => $attribute->value_type,
                    'sort_order' => (int) $attribute->sort_order,
                ])->values()->all()),
            'product_template_id' => $this->product_template_id,
            'product_template_schema_version' => $this->product_template_schema_version,
            'product_template' => $this->whenLoaded('productTemplate', fn () => new ChatCatalogProductTemplateResource($this->productTemplate)),
            'chat_catalog_category_id' => $this->chat_catalog_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => null,
                'price' => $variant->price !== null ? Money::of((string) $variant->price) : null,
                'compare_at_price' => null,
                'sale_price' => null,
                'currency' => $variant->currency,
                'stock' => $variant->stock,
                'inventory_policy' => $variant->inventory_policy?->value,
                'is_active' => (bool) $variant->is_active,
                'is_visible' => (bool) $variant->is_visible,
                'status' => $variant->status,
                'sort_order' => (int) $variant->sort_order,
                'attributes' => $variant->attributes ?? [],
                'structured_attributes' => $variant->relationLoaded('structuredAttributes')
                    ? $variant->structuredAttributes->map(fn ($attribute) => [
                        'id' => $attribute->id,
                        'key' => $attribute->attribute_key,
                        'attribute_key' => $attribute->attribute_key,
                        'value' => $attribute->attribute_value,
                        'attribute_value' => $attribute->attribute_value,
                        'value_type' => $attribute->value_type,
                        'sort_order' => (int) $attribute->sort_order,
                    ])->values()->all()
                    : [],
            ])->values()->all()),
        ];
    }
}
