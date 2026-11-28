<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogItemClassification;
use App\Models\Commerce\CommerceCompatibility;
use App\Models\Commerce\CommerceEntityAttributeValue;
use App\Models\Commerce\Seller;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Worker\WorkerEngagementProduct;
use Database\Factories\ChatCatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chat-domain catalog only. Not Finance Product and not PosMenuItem.
 */
#[Fillable([
    'workspace_id',
    'product_template_id',
    'product_template_schema_version',
    'commerce_seller_id',
    'chat_catalog_category_id',
    'name',
    'slug',
    'internal_name',
    'description',
    'short_description',
    'sku',
    'barcode',
    'brand',
    'manufacturer',
    'model',
    'external_reference',
    'customer_description',
    'usage_instructions',
    'specifications',
    'highlights',
    'warnings',
    'terms',
    'item_kind',
    'tangibility',
    'classification',
    'digital_subtype',
    'service_type',
    'price',
    'worker_min_price',
    'worker_max_price',
    'compare_at_price',
    'sale_price',
    'currency',
    'tax_display_behavior',
    'price_valid_from',
    'price_valid_until',
    'stock',
    'inventory_policy',
    'is_active',
    'is_featured',
    'is_bestseller',
    'is_new',
    'is_recommended',
    'is_searchable',
    'is_visible',
    'publication_status',
    'published_at',
    'archived_at',
    'sort_order',
    'image_path',
    'attributes',
    'policies',
    'ai_safe_profile',
    'tags',
    'aliases',
    'search_phrases',
])]
class ChatCatalogItem extends WorkspaceScopedModel
{
    /** @use HasFactory<ChatCatalogItemFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'worker_min_price' => 'decimal:2',
            'worker_max_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_new' => 'boolean',
            'is_recommended' => 'boolean',
            'is_searchable' => 'boolean',
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'price_valid_from' => 'datetime',
            'price_valid_until' => 'datetime',
            'classification' => ChatCatalogItemClassification::class,
            'inventory_policy' => ChatCatalogInventoryPolicy::class,
            'attributes' => 'array',
            'specifications' => 'array',
            'highlights' => 'array',
            'policies' => 'array',
            'ai_safe_profile' => 'array',
            'tags' => 'array',
            'aliases' => 'array',
            'search_phrases' => 'array',
            'product_template_schema_version' => 'integer',
        ];
    }

    public function productTemplate(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogProductTemplate::class, 'product_template_id');
    }

    public function isDiscoverable(): bool
    {
        if (! $this->is_active || ! $this->is_visible) {
            return false;
        }

        $rawStatus = strtolower(trim((string) ($this->status ?? '')));
        if ($rawStatus !== '' && in_array($rawStatus, ['inactive', 'disabled', 'archived', 'draft'], true)) {
            return false;
        }

        if ($this->is_searchable === false) {
            return false;
        }

        if ($this->publication_status === 'archived' || $this->archived_at !== null) {
            return false;
        }

        if ($this->publication_status === 'draft') {
            return false;
        }

        if ($this->published_at !== null && $this->published_at->isFuture()) {
            return false;
        }

        return true;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }

    public function digitalProfile(): HasOne
    {
        return $this->hasOne(ChatCatalogItemDigitalProfile::class, 'chat_catalog_item_id');
    }

    public function digitalPools(): HasMany
    {
        return $this->hasMany(ChatCatalogDigitalInventoryPool::class, 'chat_catalog_item_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ChatCatalogItemMedia::class, 'chat_catalog_item_id');
    }

    public function structuredAttributes(): HasMany
    {
        return $this->hasMany(ChatCatalogItemAttribute::class, 'chat_catalog_item_id');
    }

    public function searchTerms(): HasMany
    {
        return $this->hasMany(ChatCatalogItemSearchTerm::class, 'chat_catalog_item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogCategory::class, 'chat_catalog_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ChatCatalogItemVariant::class, 'chat_catalog_item_id');
    }

    public function workerEngagementProducts(): HasMany
    {
        return $this->hasMany(WorkerEngagementProduct::class, 'chat_catalog_item_id');
    }

    public function intelligenceAttributeValues(): HasMany
    {
        return $this->hasMany(CommerceEntityAttributeValue::class, 'entity_id')
            ->where('entity_type', 'chat_catalog_item');
    }

    public function intelligenceCompatibilities(): HasMany
    {
        return $this->hasMany(CommerceCompatibility::class, 'source_entity_id')
            ->where('source_entity_type', 'chat_catalog_item');
    }

    public function primaryImagePath(): ?string
    {
        if (is_string($this->image_path) && trim($this->image_path) !== '') {
            return trim($this->image_path);
        }

        if ($this->relationLoaded('media')) {
            $media = $this->media->first(fn ($m) => ($m->kind === 'image' || empty($m->kind)) && ! empty($m->path) && (bool) $m->is_visible);

            return $media?->path;
        }

        $media = ChatCatalogItemMedia::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace_id)
            ->where('chat_catalog_item_id', $this->id)
            ->where(fn ($q) => $q->where('kind', 'image')->orWhereNull('kind'))
            ->whereNotNull('path')
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();

        return $media?->path;
    }

    public function primaryImageDisk(): string
    {
        if (is_string($this->image_path) && trim($this->image_path) !== '') {
            return 'public';
        }

        if ($this->relationLoaded('media')) {
            $media = $this->media->first(fn ($m) => ($m->kind === 'image' || empty($m->kind)) && ! empty($m->path) && (bool) $m->is_visible);

            return $media?->disk ?: 'public';
        }

        $media = ChatCatalogItemMedia::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace_id)
            ->where('chat_catalog_item_id', $this->id)
            ->where(fn ($q) => $q->where('kind', 'image')->orWhereNull('kind'))
            ->whereNotNull('path')
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();

        return $media?->disk ?: 'public';
    }

    public function hasImage(): bool
    {
        return $this->primaryImagePath() !== null;
    }

    public function effectivePrice(): string
    {
        return (string) ($this->sale_price ?? $this->price ?? '0.00');
    }
}
