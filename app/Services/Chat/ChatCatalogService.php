<?php

namespace App\Services\Chat;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogCategorySearchTerm;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemAttribute;
use App\Models\ChatCatalogItemSearchTerm;
use App\Models\ChatCatalogItemVariant;
use App\Models\Commerce\Seller;
use App\Models\Workspace;
use App\Support\Chat\ChatCatalogDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ChatCatalogService
{
    public function __construct(
        private readonly ChatCatalogDataSanitizer $sanitizer,
        private readonly ChatCatalogDigitalInventoryService $digitalInventory,
        private readonly ChatCatalogRootService $catalogRoot,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(Workspace $workspace, array $data): ChatCatalogItem
    {
        return DB::transaction(function () use ($workspace, $data): ChatCatalogItem {
            $item = ChatCatalogItem::query()->create($this->itemPayload($workspace, $data));
            if ($item->classification?->value === 'digital') {
                $this->digitalInventory->ensureDigitalProfile($item, null);
            }

            $this->syncVariants($workspace, $item, $data['variants'] ?? []);
            $this->syncMedia($workspace, $item, $data['media'] ?? []);
            $this->syncStructuredAttributes($workspace, $item, $data['structured_attributes'] ?? []);
            $this->syncSearchTerms($workspace, $item);

            return $item->fresh(['category', 'variants', 'media', 'structuredAttributes']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(ChatCatalogItem $item, array $data): ChatCatalogItem
    {
        $workspace = $item->workspace;
        if (! $workspace) {
            throw new InvalidArgumentException('Chat catalog item is missing workspace.');
        }

        return DB::transaction(function () use ($item, $workspace, $data): ChatCatalogItem {
            $item->update($this->itemPayload($workspace, $data, $item));
            if ($item->classification?->value === 'digital') {
                $this->digitalInventory->ensureDigitalProfile($item, null);
            } else {
                if ($item->digitalPools()->exists() || $item->variants()->whereHas('digitalPools')->exists()) {
                    throw new InvalidArgumentException('A catalog item with digital inventory cannot stop being digital.');
                }
                $item->digitalProfile()?->delete();
                $item->variants()->each(fn (ChatCatalogItemVariant $variant) => $variant->digitalProfile()?->delete());
            }

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($workspace, $item, is_array($data['variants']) ? $data['variants'] : []);
            }
            if (array_key_exists('media', $data)) {
                $this->syncMedia($workspace, $item, is_array($data['media']) ? $data['media'] : []);
            }
            if (array_key_exists('structured_attributes', $data)) {
                $this->syncStructuredAttributes($workspace, $item, is_array($data['structured_attributes']) ? $data['structured_attributes'] : []);
            }
            if (array_intersect(array_keys($data), ['tags', 'aliases', 'search_phrases']) !== []) {
                $this->syncSearchTerms($workspace, $item);
            }

            return $item->fresh(['category', 'variants', 'media', 'structuredAttributes']);
        });
    }

    public function setActive(ChatCatalogItem $item, bool $active): ChatCatalogItem
    {
        $item->update(['is_active' => $active]);

        return $item->fresh(['category', 'variants']);
    }

    public function deleteItem(ChatCatalogItem $item): void
    {
        $item->variants()->delete();
        $item->media()->delete();
        $item->structuredAttributes()->delete();
        $item->searchTerms()->delete();
        $item->digitalPools()->delete();
        $item->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(Workspace $workspace, array $data): ChatCatalogCategory
    {
        $slug = $this->uniqueCategorySlug($workspace, (string) ($data['slug'] ?? ''), (string) $data['name']);
        $seo = $this->sanitizer->sanitizeArray(is_array($data['seo'] ?? null) ? $data['seo'] : []);
        $this->assertCategoryCanonicalSlug($seo, $slug);
        $category = ChatCatalogCategory::query()->create([
            'workspace_id' => $workspace->id,
            'parent_id' => $this->resolveParentCategoryId($workspace, $data['parent_id'] ?? null),
            'name' => $this->sanitizer->plainText((string) $data['name'], 255),
            'slug' => $slug,
            'description' => $this->sanitizer->plainText($data['description'] ?? null),
            'image_path' => $data['image_path'] ?? null,
            'status' => $data['status'] ?? 'published',
            'is_visible' => array_key_exists('is_visible', $data) ? (bool) $data['is_visible'] : true,
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'seo' => $seo,
            'localization' => $this->sanitizer->sanitizeArray(is_array($data['localization'] ?? null) ? $data['localization'] : []),
            'aliases' => $this->stringList($data['aliases'] ?? []),
        ]);
        $this->syncCategorySearchTerms($workspace, $category);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(ChatCatalogCategory $category, array $data): ChatCatalogCategory
    {
        $workspace = $category->workspace;
        if (! $workspace) {
            throw new InvalidArgumentException('Chat catalog category is missing workspace.');
        }

        $payload = [
            'name' => array_key_exists('name', $data) ? $this->sanitizer->plainText((string) $data['name'], 255) : $category->name,
            'description' => array_key_exists('description', $data) ? $this->sanitizer->plainText($data['description']) : $category->description,
            'image_path' => array_key_exists('image_path', $data) ? $data['image_path'] : $category->image_path,
            'status' => $data['status'] ?? $category->status,
            'is_visible' => array_key_exists('is_visible', $data) ? (bool) $data['is_visible'] : $category->is_visible,
            'is_featured' => array_key_exists('is_featured', $data) ? (bool) $data['is_featured'] : $category->is_featured,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $category->is_active,
            'sort_order' => (int) ($data['sort_order'] ?? $category->sort_order),
            'seo' => array_key_exists('seo', $data) ? $this->sanitizer->sanitizeArray(is_array($data['seo']) ? $data['seo'] : []) : ($category->seo ?? []),
            'localization' => array_key_exists('localization', $data) ? $this->sanitizer->sanitizeArray(is_array($data['localization']) ? $data['localization'] : []) : ($category->localization ?? []),
            'aliases' => array_key_exists('aliases', $data) ? $this->stringList($data['aliases']) : ($category->aliases ?? []),
        ];

        if (array_key_exists('parent_id', $data)) {
            $payload['parent_id'] = $this->resolveParentCategoryId($workspace, $data['parent_id'], $category);
        }
        if (array_key_exists('slug', $data)) {
            $payload['slug'] = $this->uniqueCategorySlug($workspace, (string) $data['slug'], (string) $payload['name'], $category->id);
        }
        $this->assertCategoryCanonicalSlug($payload['seo'], $payload['slug'] ?? $category->slug);

        $category->update($payload);
        if (array_key_exists('aliases', $data)) {
            $this->syncCategorySearchTerms($workspace, $category);
        }

        return $category->fresh();
    }

    public function deleteCategory(ChatCatalogCategory $category): void
    {
        if ($category->items()->exists()) {
            throw new InvalidArgumentException('لا يمكن حذف تصنيف مرتبط بأصناف. انقل الأصناف أولاً.');
        }
        if ($category->children()->exists()) {
            throw new InvalidArgumentException('لا يمكن حذف تصنيف يحتوي على تصنيفات فرعية.');
        }

        $category->delete();
    }

    private function syncCategorySearchTerms(Workspace $workspace, ChatCatalogCategory $category): void
    {
        $category->searchTerms()->delete();
        foreach ($category->aliases ?? [] as $term) {
            if (! is_string($term)) {
                continue;
            }
            $cleanTerm = $this->sanitizer->plainText($term, 255);
            if ($cleanTerm === null || $cleanTerm === '') {
                continue;
            }
            ChatCatalogCategorySearchTerm::query()->create([
                'workspace_id' => $workspace->id,
                'chat_catalog_category_id' => $category->id,
                'term' => $cleanTerm,
                'language' => $this->detectSearchTermLanguage($cleanTerm),
            ]);
        }
    }

    private function resolveCategoryId(Workspace $workspace, mixed $categoryId): ?int
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $id = (int) $categoryId;
        $exists = ChatCatalogCategory::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $id)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('تصنيف كتالوج الشات غير موجود في مساحة العمل.');
        }

        return $id;
    }

    private function resolveSellerId(Workspace $workspace, mixed $sellerId): ?int
    {
        if ($sellerId === null || $sellerId === '') {
            return null;
        }

        $id = (int) $sellerId;
        $exists = Seller::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException('البائع غير موجود في مساحة العمل.');
        }

        return $id;
    }

    private function resolveParentCategoryId(
        Workspace $workspace,
        mixed $parentId,
        ?ChatCatalogCategory $category = null,
    ): ?int {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = ChatCatalogCategory::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey((int) $parentId)
            ->first();
        if (! $parent) {
            throw new InvalidArgumentException('التصنيف الأب غير موجود في مساحة العمل.');
        }
        if ($category && (int) $parent->id === (int) $category->id) {
            throw new InvalidArgumentException('لا يمكن أن يكون التصنيف أباً لنفسه.');
        }
        if ($category && $this->categoryContains($parent, $category->id)) {
            throw new InvalidArgumentException('لا يمكن إنشاء علاقة تصنيفات دائرية.');
        }

        return $parent->id;
    }

    private function categoryContains(ChatCatalogCategory $root, int $id): bool
    {
        $current = $root;
        $guard = 0;
        while ($current->parent_id !== null && $guard++ < 64) {
            if ((int) $current->parent_id === $id) {
                return true;
            }
            $current = ChatCatalogCategory::withoutGlobalScopes()
                ->where('workspace_id', $root->workspace_id)
                ->find($current->parent_id);
            if (! $current) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     */
    private function syncMedia(Workspace $workspace, ChatCatalogItem $item, array $media): void
    {
        $incoming = collect($media)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        $item->media()->whereNotIn('id', $incoming)->delete();

        foreach ($media as $row) {
            if (! is_array($row) || trim((string) ($row['path'] ?? '')) === '') {
                continue;
            }
            $kind = in_array($row['kind'] ?? 'gallery', ['primary', 'gallery', 'thumbnail', 'video_reference'], true)
                ? $row['kind']
                : 'gallery';
            $path = $this->sanitizer->plainText((string) $row['path'], 500);
            $scheme = $path === null ? null : parse_url($path, PHP_URL_SCHEME);
            if ($kind === 'video_reference'
                && ($path === null || filter_var($path, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['https', 'http'], true))) {
                throw new InvalidArgumentException('Video references must use an absolute URL.');
            }
            if ($kind !== 'video_reference' && ($path === null || str_contains($path, '..'))) {
                throw new InvalidArgumentException('Public media paths cannot traverse storage directories.');
            }
            // Public catalog media cannot be smuggled into a private disk.
            $payload = [
                'workspace_id' => $workspace->id,
                'kind' => $kind,
                'disk' => 'public',
                'path' => $path,
                'alt' => $this->sanitizer->plainText($row['alt'] ?? null, 500),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'focal_metadata' => $this->sanitizer->sanitizeArray(is_array($row['focal_metadata'] ?? null) ? $row['focal_metadata'] : []),
                'is_visible' => array_key_exists('is_visible', $row) ? (bool) $row['is_visible'] : true,
            ];
            if (! empty($row['id'])) {
                $item->media()->whereKey((int) $row['id'])->update($payload);
            } else {
                $item->media()->create($payload);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     */
    private function syncStructuredAttributes(
        Workspace $workspace,
        ChatCatalogItem $item,
        array $attributes,
        ?ChatCatalogItemVariant $variant = null,
    ): void {
        $relation = $variant
            ? $variant->structuredAttributes()
            : $item->structuredAttributes()->whereNull('chat_catalog_item_variant_id');
        $incoming = collect($attributes)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        $relation->whereNotIn('id', $incoming)->delete();

        foreach ($attributes as $row) {
            if (! is_array($row) || trim((string) ($row['attribute_key'] ?? '')) === '') {
                continue;
            }
            $payload = [
                'workspace_id' => $workspace->id,
                'chat_catalog_item_id' => $item->id,
                'chat_catalog_item_variant_id' => $variant?->id,
                'attribute_key' => $this->sanitizer->plainText((string) $row['attribute_key'], 128),
                'attribute_value' => $this->sanitizer->plainText($row['attribute_value'] ?? null, 2000),
                'value_type' => in_array($row['value_type'] ?? 'string', ['string', 'number', 'boolean', 'measurement'], true)
                    ? $row['value_type']
                    : 'string',
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
            if (! empty($row['id'])) {
                ChatCatalogItemAttribute::query()
                    ->where('chat_catalog_item_id', $item->id)
                    ->where('id', (int) $row['id'])
                    ->when($variant, fn ($query) => $query->where('chat_catalog_item_variant_id', $variant->id))
                    ->update($payload);
            } else {
                ChatCatalogItemAttribute::query()->create($payload);
            }
        }
    }

    private function syncSearchTerms(Workspace $workspace, ChatCatalogItem $item): void
    {
        $terms = [
            'tag' => $item->tags ?? [],
            'alias' => $item->aliases ?? [],
            'synonym' => $item->search_phrases ?? [],
        ];
        $rows = [];
        foreach ($terms as $type => $values) {
            foreach ($values as $term) {
                if (! is_string($term) || trim($term) === '') {
                    continue;
                }
                $cleanTerm = $this->sanitizer->plainText($term, 255);
                if ($cleanTerm === null || $cleanTerm === '') {
                    continue;
                }
                $rows[] = [
                    'workspace_id' => $workspace->id,
                    'chat_catalog_item_id' => $item->id,
                    'term' => $cleanTerm,
                    'term_type' => $type,
                    'language' => $this->detectSearchTermLanguage($term),
                ];
            }
        }

        $item->searchTerms()->delete();
        foreach ($rows as $row) {
            ChatCatalogItemSearchTerm::query()->create($row);
        }
    }

    private function detectSearchTermLanguage(string $term): ?string
    {
        if (preg_match('/\p{Arabic}/u', $term)) {
            return 'ar';
        }
        if (preg_match('/[a-z]/i', $term)) {
            return 'en';
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Workspace $workspace, ChatCatalogItem $item, array $variants): void
    {
        $incomingIds = collect($variants)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values()->all();

        $removedVariants = $item->variants()
            ->whereNotIn('id', $incomingIds)
            ->get();
        foreach ($removedVariants as $removedVariant) {
            if ($removedVariant->digitalPools()->exists()) {
                throw new InvalidArgumentException('لا يمكن حذف نسخة مرتبطة بمخزون رقمي. عطّلها أو انقل الحوض أولاً.');
            }
            $removedVariant->digitalProfile()?->delete();
            $removedVariant->structuredAttributes()->delete();
            $removedVariant->delete();
        }

        foreach ($variants as $variantData) {
            if (! is_array($variantData) || trim((string) ($variantData['name'] ?? '')) === '') {
                continue;
            }

            $payload = [
                'workspace_id' => $workspace->id,
                'name' => $this->sanitizer->plainText((string) $variantData['name'], 255),
                'sku' => $variantData['sku'] ?? null,
                'barcode' => $variantData['barcode'] ?? null,
                'price' => $variantData['price'] ?? null,
                'compare_at_price' => $variantData['compare_at_price'] ?? null,
                'sale_price' => $variantData['sale_price'] ?? null,
                'currency' => strtoupper((string) ($variantData['currency'] ?? $item->currency)),
                'status' => $variantData['status'] ?? 'published',
                'is_visible' => array_key_exists('is_visible', $variantData) ? (bool) $variantData['is_visible'] : true,
                'sort_order' => (int) ($variantData['sort_order'] ?? 0),
                'stock' => $variantData['stock'] ?? null,
                'inventory_policy' => $variantData['inventory_policy'] ?? $item->inventory_policy?->value ?? ChatCatalogDefaults::defaultItemInventoryPolicy()->value,
                'is_active' => array_key_exists('is_active', $variantData) ? (bool) $variantData['is_active'] : true,
                'attributes' => $this->sanitizer->sanitizeArray(is_array($variantData['attributes'] ?? null) ? $variantData['attributes'] : []),
            ];

            if (! empty($variantData['id'])) {
                $variant = ChatCatalogItemVariant::query()
                    ->where('chat_catalog_item_id', $item->id)
                    ->where('id', (int) $variantData['id'])
                    ->firstOrFail();
                $variant->update($payload);
                if (array_key_exists('structured_attributes', $variantData)) {
                    $this->syncStructuredAttributes($workspace, $item, $variantData['structured_attributes'], $variant);
                }

                continue;
            }

            $variant = $item->variants()->create($payload);
            if (array_key_exists('structured_attributes', $variantData)) {
                $this->syncStructuredAttributes($workspace, $item, $variantData['structured_attributes'], $variant);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function itemPayload(Workspace $workspace, array $data, ?ChatCatalogItem $existing = null): array
    {
        $name = array_key_exists('name', $data)
            ? $this->sanitizer->plainText((string) $data['name'], 255)
            : $existing?->name;
        if (! $name) {
            throw new InvalidArgumentException('Chat catalog item name is required.');
        }

        $classification = $data['classification'] ?? $existing?->classification?->value;
        $classification ??= ($data['tangibility'] ?? $existing?->tangibility) === 'intangible'
            ? 'digital'
            : 'physical';
        $digitalSubtype = array_key_exists('digital_subtype', $data) ? $data['digital_subtype'] : $existing?->digital_subtype;
        $serviceType = array_key_exists('service_type', $data) ? $data['service_type'] : $existing?->service_type;
        if ($classification !== 'digital' && $digitalSubtype !== null) {
            throw new InvalidArgumentException('Digital subtype requires a digital catalog item.');
        }
        if ($classification !== 'service' && $serviceType !== null) {
            throw new InvalidArgumentException('Service type requires a service catalog item.');
        }
        $currency = strtoupper((string) (
            $data['currency']
            ?? $existing?->currency
            ?? $this->catalogRoot->forWorkspace($workspace)->default_currency
            ?? ChatCatalogDefaults::DEFAULT_CURRENCY
        ));

        $payload = [
            'workspace_id' => $workspace->id,
            'commerce_seller_id' => array_key_exists('commerce_seller_id', $data)
                ? $this->resolveSellerId($workspace, $data['commerce_seller_id'])
                : $existing?->commerce_seller_id,
            'chat_catalog_category_id' => array_key_exists('chat_catalog_category_id', $data)
                ? $this->resolveCategoryId($workspace, $data['chat_catalog_category_id'])
                : $existing?->chat_catalog_category_id,
            'name' => $name,
            'internal_name' => array_key_exists('internal_name', $data)
                ? $this->sanitizer->plainText($data['internal_name'], 255)
                : $existing?->internal_name,
            'description' => array_key_exists('description', $data)
                ? $this->sanitizer->plainText($data['description'])
                : $existing?->description,
            'short_description' => array_key_exists('short_description', $data)
                ? $this->sanitizer->plainText($data['short_description'], 512)
                : $existing?->short_description,
            'sku' => array_key_exists('sku', $data) ? $this->sanitizer->plainText($data['sku'], 64) : $existing?->sku,
            'barcode' => array_key_exists('barcode', $data) ? $this->sanitizer->plainText($data['barcode'], 64) : $existing?->barcode,
            'brand' => array_key_exists('brand', $data) ? $this->sanitizer->plainText($data['brand'], 255) : $existing?->brand,
            'manufacturer' => array_key_exists('manufacturer', $data) ? $this->sanitizer->plainText($data['manufacturer'], 255) : $existing?->manufacturer,
            'model' => array_key_exists('model', $data) ? $this->sanitizer->plainText($data['model'], 255) : $existing?->model,
            'external_reference' => array_key_exists('external_reference', $data) ? $this->sanitizer->plainText($data['external_reference'], 255) : $existing?->external_reference,
            'customer_description' => array_key_exists('customer_description', $data) ? $this->sanitizer->plainText($data['customer_description']) : $existing?->customer_description,
            'usage_instructions' => array_key_exists('usage_instructions', $data) ? $this->sanitizer->plainText($data['usage_instructions']) : $existing?->usage_instructions,
            'specifications' => array_key_exists('specifications', $data) ? $this->sanitizer->sanitizeArray(is_array($data['specifications']) ? $data['specifications'] : []) : ($existing?->specifications ?? []),
            'highlights' => array_key_exists('highlights', $data) ? $this->stringList($data['highlights']) : ($existing?->highlights ?? []),
            'warnings' => array_key_exists('warnings', $data) ? $this->sanitizer->plainText($data['warnings']) : $existing?->warnings,
            'terms' => array_key_exists('terms', $data) ? $this->sanitizer->plainText($data['terms']) : $existing?->terms,
            // v1 fields remain maintained for existing mobile/web clients.
            'item_kind' => $this->normalizeKind($data['item_kind'] ?? $existing?->item_kind ?? 'product'),
            'tangibility' => $this->normalizeTangibility($data['tangibility'] ?? $existing?->tangibility ?? ($classification === 'physical' ? 'tangible' : 'intangible')),
            'classification' => $classification,
            'digital_subtype' => $digitalSubtype,
            'service_type' => $serviceType,
            'price' => $data['price'] ?? $existing?->price ?? '0.00',
            'compare_at_price' => array_key_exists('compare_at_price', $data) ? $data['compare_at_price'] : $existing?->compare_at_price,
            'sale_price' => array_key_exists('sale_price', $data) ? $data['sale_price'] : $existing?->sale_price,
            'currency' => $currency,
            'tax_display_behavior' => $data['tax_display_behavior'] ?? $existing?->tax_display_behavior ?? 'tax_exclusive',
            'price_valid_from' => array_key_exists('price_valid_from', $data) ? $data['price_valid_from'] : $existing?->price_valid_from,
            'price_valid_until' => array_key_exists('price_valid_until', $data) ? $data['price_valid_until'] : $existing?->price_valid_until,
            'stock' => array_key_exists('stock', $data) ? $data['stock'] : $existing?->stock,
            'inventory_policy' => $data['inventory_policy'] ?? $existing?->inventory_policy?->value ?? ChatCatalogDefaults::defaultItemInventoryPolicy()->value,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
            'is_featured' => array_key_exists('is_featured', $data) ? (bool) $data['is_featured'] : ($existing?->is_featured ?? false),
            'is_bestseller' => array_key_exists('is_bestseller', $data) ? (bool) $data['is_bestseller'] : ($existing?->is_bestseller ?? false),
            'is_new' => array_key_exists('is_new', $data) ? (bool) $data['is_new'] : ($existing?->is_new ?? false),
            'is_recommended' => array_key_exists('is_recommended', $data) ? (bool) $data['is_recommended'] : ($existing?->is_recommended ?? false),
            'is_searchable' => array_key_exists('is_searchable', $data) ? (bool) $data['is_searchable'] : ($existing?->is_searchable ?? true),
            'is_visible' => array_key_exists('is_visible', $data) ? (bool) $data['is_visible'] : ($existing?->is_visible ?? true),
            'publication_status' => $data['publication_status'] ?? $existing?->publication_status ?? 'published',
            'published_at' => array_key_exists('published_at', $data) ? $data['published_at'] : ($existing?->published_at ?? now()),
            'archived_at' => array_key_exists('archived_at', $data) ? $data['archived_at'] : $existing?->archived_at,
            'sort_order' => (int) ($data['sort_order'] ?? $existing?->sort_order ?? 0),
            'image_path' => array_key_exists('image_path', $data) ? $data['image_path'] : $existing?->image_path,
            'attributes' => array_key_exists('attributes', $data) ? $this->sanitizer->sanitizeArray(is_array($data['attributes']) ? $data['attributes'] : []) : ($existing?->attributes ?? []),
            'policies' => array_key_exists('policies', $data) ? $this->sanitizer->sanitizeArray(is_array($data['policies']) ? $data['policies'] : []) : ($existing?->policies ?? []),
            'ai_safe_profile' => array_key_exists('ai_safe_profile', $data) ? $this->sanitizer->aiSafeProfile(is_array($data['ai_safe_profile']) ? $data['ai_safe_profile'] : []) : ($existing?->ai_safe_profile ?? []),
            'tags' => array_key_exists('tags', $data) ? $this->stringList($data['tags']) : ($existing?->tags ?? []),
            'aliases' => array_key_exists('aliases', $data) ? $this->stringList($data['aliases']) : ($existing?->aliases ?? []),
            'search_phrases' => array_key_exists('search_phrases', $data) ? $this->stringList($data['search_phrases']) : ($existing?->search_phrases ?? []),
        ];

        if ($classification === 'digital') {
            $payload['tangibility'] = 'intangible';
        }

        $payload['slug'] = array_key_exists('slug', $data)
            ? $this->uniqueSlug($workspace, (string) $data['slug'], $name, $existing?->id)
            : ($existing?->slug ?? $this->uniqueSlug($workspace, '', $name));

        return $payload;
    }

    private function uniqueSlug(Workspace $workspace, string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : $name);
        if ($base === '') {
            $base = 'item';
        }

        $candidate = $base;
        $suffix = 2;
        while (
            ChatCatalogItem::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueCategorySlug(
        Workspace $workspace,
        string $slug,
        string $name,
        ?int $ignoreId = null,
    ): string {
        $base = Str::slug($slug !== '' ? $slug : $name);
        if ($base === '') {
            $base = 'category';
        }

        $candidate = $base;
        $suffix = 2;
        while (
            ChatCatalogCategory::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $seo
     */
    private function assertCategoryCanonicalSlug(array $seo, string $slug): void
    {
        if (($seo['canonical_slug'] ?? '') !== '' && $seo['canonical_slug'] !== $slug) {
            throw new InvalidArgumentException('Category canonical slug must match the category slug.');
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values, int $maxPerValue = 255): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn ($value): bool => is_string($value))
            ->map(fn (string $value): ?string => $this->sanitizer->plainText($value, $maxPerValue))
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->unique()
            ->take(100)
            ->values()
            ->all();
    }

    private function normalizeKind(mixed $kind): string
    {
        $value = strtolower(trim((string) $kind));

        return in_array($value, ['product', 'service'], true) ? $value : 'product';
    }

    private function normalizeTangibility(mixed $tangibility): string
    {
        $value = strtolower(trim((string) $tangibility));

        return in_array($value, ['tangible', 'intangible'], true) ? $value : 'tangible';
    }
}
