<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogSortPolicy;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Workspace;
use App\Support\Chat\ArabicSearchNormalizer;
use App\Support\Chat\ArabicSearchTokenExpander;
use App\Support\Chat\CatalogAvailabilityFact;
use App\Support\Chat\CatalogBrowseQueryDetector;
use App\Support\Chat\GulfArabicInterpreter;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Token search over Chat Catalog only. Never reads Product or PosMenuItem.
 */
class ChatCatalogRetrievalService
{
    public function __construct(
        private readonly ChatCatalogRootService $catalogRoot,
        private readonly CatalogAvailabilityService $availability,
        private readonly ChatCatalogSortService $sortService,
        private readonly ChatCatalogInventoryPresenter $inventory,
        private readonly ChatCatalogPricePresenter $prices,
        private readonly ChatCatalogDataSanitizer $sanitizer,
        private readonly ArabicSearchTokenExpander $tokenExpander,
        private readonly GulfArabicInterpreter $gulfArabic,
        private readonly ProductTemplateValidationService $productTemplateValidation,
        private readonly ArabicSearchNormalizer $normalizer,
        private readonly CatalogBrowseQueryDetector $browseQueries,
    ) {}

    /**
     * Inbox AI lookup of workspace inventory items. Storefront catalog settings
     * (published status, channel visibility, inquiry_mode, hours) must not hide items.
     *
     * @return list<array<string, mixed>>
     */
    public function searchForAi(Workspace $workspace, string $query, int $limit = 12): array
    {
        return app(WorkspaceContext::class)->runFor(
            $workspace,
            fn (): array => $this->searchForPromptInWorkspace($workspace, $query, $limit, 'chat', forAi: true),
        );
    }

    public function shouldLookupForAssistant(string $query): bool
    {
        $tokens = $this->tokens($query);

        return $tokens !== [] || $this->browseQueries->isBrowseCatalogQuery($query, $tokens);
    }

    /**
     * Trusted catalog rows for an assistant turn.
     *
     * Status is one of: not_loaded, listed, catalog_empty, no_matching_type, name_not_found, search_failed.
     * has_more is true only when another eligible row exists beyond the returned page.
     *
     * @return array{status: string, items: list<array<string, mixed>>, has_more: bool}
     */
    public function lookupForAssistant(Workspace $workspace, string $query, int $limit = 8): array
    {
        if (! $this->shouldLookupForAssistant($query)) {
            return $this->assistantLookup('not_loaded');
        }

        try {
            return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $query, $limit): array {
                $limit = max(1, $limit);
                $rows = $this->searchForPromptInWorkspace($workspace, $query, $limit + 1, 'chat', forAi: true);
                $items = [];
                $queryTokens = $this->tokens($query);
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $item = $this->assistantItem($row, $queryTokens);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }

                $hasMore = count($items) > $limit;
                if ($hasMore) {
                    $items = array_slice($items, 0, $limit);
                }

                if ($items !== []) {
                    return $this->assistantLookup('listed', $items, $hasMore);
                }

                $catalog = $this->catalogRoot->forWorkspace($workspace);
                $tokens = $this->tokens($query);
                $typedBrowse = $this->browseQueries->isBrowseCatalogQuery($query, $tokens)
                    && $this->browseQueries->browseItemKindFilter($query, $tokens) !== null;
                $hasDisplayable = $this->listDiscoverableForPrompt($workspace, $catalog, 1, 'chat', null, true) !== [];

                if (! $hasDisplayable) {
                    return $this->assistantLookup('catalog_empty');
                }

                return $this->assistantLookup($typedBrowse ? 'no_matching_type' : 'name_not_found');
            });
        } catch (Throwable $exception) {
            Log::warning('catalog.assistant_lookup_failed', [
                'workspace_id' => $workspace->id,
                'exception_type' => $exception::class,
            ]);

            return $this->assistantLookup('search_failed');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{status: string, items: list<array<string, mixed>>, has_more: bool}
     */
    private function assistantLookup(string $status, array $items = [], bool $hasMore = false): array
    {
        return [
            'status' => $status,
            'items' => $items,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $queryTokens
     * @return array<string, mixed>|null
     */
    private function assistantItem(array $row, array $queryTokens = []): ?array
    {
        $id = $row['catalog_item_id'] ?? null;
        $name = $row['name'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0 || ! is_string($name) || trim($name) === '') {
            return null;
        }

        $item = [
            'catalog_item_id' => (int) $id,
            'name' => trim($name),
        ];
        $description = $row['description'] ?? null;
        if (is_string($description) && trim($description) !== '') {
            $item['description'] = mb_substr(trim($description), 0, 240);
        }
        if (is_scalar($row['price'] ?? null) && (string) $row['price'] !== '') {
            $item['price'] = (string) $row['price'];
        }
        if (is_string($row['currency'] ?? null) && trim($row['currency']) !== '') {
            $item['currency'] = trim($row['currency']);
        }
        $availability = CatalogAvailabilityFact::token($row['availability'] ?? null);
        if ($availability !== null) {
            $item['availability'] = $availability;
        }
        if (is_int($row['stock'] ?? null) || (is_string($row['stock'] ?? null) && is_numeric($row['stock']))) {
            $item['stock'] = (int) $row['stock'];
        }

        $variants = $this->assistantVariants($row['variants'] ?? null, $queryTokens);
        if ($variants['variants'] !== []) {
            $item['variants'] = $variants['variants'];
            $item['has_more_variants'] = $variants['has_more'];
        }

        return $item;
    }

    /**
     * Copies of the parent item. A matching copy is marked, and the rest stay
     * attached so a follow-up can compare them without treating them as other products.
     *
     * @param  list<string>  $queryTokens
     * @return array{variants: list<array<string, mixed>>, has_more: bool}
     */
    private function assistantVariants(mixed $rows, array $queryTokens): array
    {
        if (! is_array($rows)) {
            return ['variants' => [], 'has_more' => false];
        }

        $variants = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $variant = $this->assistantVariant($row, $queryTokens);
            if ($variant !== null) {
                $variants[] = $variant;
            }
        }

        usort($variants, function (array $left, array $right): int {
            return ((int) ($right['matches_question'] ?? false)) <=> ((int) ($left['matches_question'] ?? false));
        });

        $limit = 8;
        $hasMore = count($variants) > $limit;

        return [
            'variants' => array_slice($variants, 0, $limit),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $queryTokens
     * @return array<string, mixed>|null
     */
    private function assistantVariant(array $row, array $queryTokens): ?array
    {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0 || ! is_string($name) || trim($name) === '') {
            return null;
        }

        $variant = [
            'id' => (int) $id,
            'name' => trim($name),
        ];
        if (is_scalar($row['price'] ?? null) && (string) $row['price'] !== '') {
            $variant['price'] = (string) $row['price'];
        }
        if (is_string($row['currency'] ?? null) && trim($row['currency']) !== '') {
            $variant['currency'] = trim($row['currency']);
        }
        $availability = CatalogAvailabilityFact::token($row['availability'] ?? null);
        if ($availability !== null) {
            $variant['availability'] = $availability;
        }
        if (is_int($row['stock'] ?? null) || (is_string($row['stock'] ?? null) && is_numeric($row['stock']))) {
            $variant['stock'] = (int) $row['stock'];
        }
        $attributes = $this->scalarAttributes($row['attributes'] ?? null);
        if ($attributes !== []) {
            $variant['attributes'] = $attributes;
        }
        $variant['matches_question'] = $this->variantMatchesQuestion($variant, $queryTokens);

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $variant
     * @param  list<string>  $queryTokens
     */
    private function variantMatchesQuestion(array $variant, array $queryTokens): bool
    {
        if ($queryTokens === []) {
            return false;
        }

        $haystack = $this->normalizer->normalize(implode(' ', [
            (string) ($variant['name'] ?? ''),
            json_encode($variant['attributes'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        ]));
        foreach ($this->tokenExpander->expandAll($queryTokens) as $token) {
            $needle = $this->normalizer->normalize($token);
            if (mb_strlen($needle) < 3) {
                continue;
            }
            if ($haystack !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string|int|float>
     */
    private function scalarAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $clean = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key) || $key === '' || count($clean) >= 6) {
                continue;
            }
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $clean[$key] = is_string($value) ? mb_substr($text, 0, 80) : $value;
        }

        return $clean;
    }

    /**
     * Storefront / public catalog search. Respects publication, channels, and inquiry mode.
     *
     * @return list<array<string, mixed>>
     */
    public function searchForPrompt(Workspace $workspace, string $query, int $limit = 12, string $channel = 'chat'): array
    {
        return app(WorkspaceContext::class)->runFor(
            $workspace,
            fn (): array => $this->searchForPromptInWorkspace($workspace, $query, $limit, $channel),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchForPromptInWorkspace(
        Workspace $workspace,
        string $query,
        int $limit,
        string $channel,
        bool $forAi = false,
    ): array {
        $catalog = $this->catalogRoot->forWorkspace($workspace);

        if (! $forAi) {
            if (! $catalog->channelEnabled($channel)) {
                return [];
            }
            if ($channel !== 'web'
                && ($catalog->customer_service_policy['inquiry_mode'] ?? 'enabled') === 'disabled') {
                return [];
            }

            if (! $this->availability->customerDiscoveryAllowed($catalog)) {
                return [];
            }

            if (! $catalog->discoveryEnabled('searchable') || ! $catalog->discoveryEnabled('discoverable')) {
                return [];
            }
        }

        $tokens = $this->tokens($query);
        if ($this->browseQueries->isBrowseCatalogQuery($query, $tokens)) {
            $itemKind = $this->browseQueries->browseItemKindFilter($query, $tokens);

            return $this->listDiscoverableForPrompt($workspace, $catalog, $limit, $channel, $itemKind, $forAi);
        }

        if ($tokens === []) {
            return [];
        }
        $searchTokens = $this->tokenExpander->expandAll($tokens);
        $sqlTokens = $this->orthographicSearchTokens($searchTokens);

        $limit = max(1, min(20, $limit));
        $weights = is_array($catalog->search_weights) ? $catalog->search_weights : [];

        $items = $this->discoverableItemsQuery($workspace, $catalog, $forAi)
            ->where(function ($builder) use ($sqlTokens, $workspace, $catalog): void {
                foreach ($sqlTokens as $token) {
                    $like = '%'.$this->escapeLike($token).'%';
                    $builder->orWhere(function ($tokenQuery) use ($like, $workspace, $catalog): void {
                        $tokenQuery->where('name', 'like', $like)
                            ->orWhereHas('searchTerms', fn ($terms) => $terms
                                ->where('term_type', 'name')
                                ->where('term', 'like', $like));

                        if ($catalog->discoveryEnabled('description_search')) {
                            $tokenQuery->orWhere('description', 'like', $like)
                                ->orWhere('short_description', 'like', $like)
                                ->orWhere('customer_description', 'like', $like)
                                ->orWhere('usage_instructions', 'like', $like);
                        }

                        if ($catalog->discoveryEnabled('brand_search')) {
                            $tokenQuery->orWhere('brand', 'like', $like);
                        }

                        if ($catalog->discoveryEnabled('sku_search')) {
                            $tokenQuery->orWhere('sku', 'like', $like);
                        }
                        if ($catalog->discoveryEnabled('tag_search')) {
                            $tokenQuery->orWhere('tags', 'like', $like)
                                ->orWhereHas('searchTerms', fn ($terms) => $terms
                                    ->where('term_type', 'tag')
                                    ->where('term', 'like', $like));
                        }
                        if ($catalog->discoveryEnabled('alias_search')) {
                            $tokenQuery->orWhere('aliases', 'like', $like)
                                ->orWhereHas('searchTerms', fn ($terms) => $terms
                                    ->where('term_type', 'alias')
                                    ->where('term', 'like', $like));
                        }
                        if ($catalog->discoveryEnabled('synonym_search')) {
                            $tokenQuery->orWhere('search_phrases', 'like', $like)
                                ->orWhereHas('searchTerms', fn ($terms) => $terms
                                    ->where('term_type', 'synonym')
                                    ->where('term', 'like', $like));
                        }

                        $tokenQuery->orWhere('attributes', 'like', $like)
                            ->orWhereHas('structuredAttributes', fn ($attributes) => $attributes
                                ->where(function ($attribute) use ($like): void {
                                    $attribute->where('attribute_key', 'like', $like)
                                        ->orWhere('attribute_value', 'like', $like);
                                }));
                        if ($catalog->discoveryEnabled('variant_discovery')) {
                            $tokenQuery->orWhereHas('variants', function ($variantQuery) use ($like, $workspace, $catalog): void {
                                $variantQuery
                                    ->withoutGlobalScopes()
                                    ->where('workspace_id', $workspace->id)
                                    ->where('is_active', true)
                                    ->where('is_visible', true)
                                    ->where('status', 'published')
                                    ->where(function ($inner) use ($like, $catalog): void {
                                        $inner->where('name', 'like', $like)
                                            ->orWhere('attributes', 'like', $like);
                                        $inner->orWhereHas('structuredAttributes', fn ($attributes) => $attributes
                                            ->where('attribute_key', 'like', $like)
                                            ->orWhere('attribute_value', 'like', $like));
                                        if ($catalog->discoveryEnabled('sku_search')) {
                                            $inner->orWhere('sku', 'like', $like);
                                        }
                                    });
                            });
                        }
                        if ($catalog->discoveryEnabled('category_discovery')) {
                            $tokenQuery->orWhereHas('category', function ($categoryQuery) use ($like): void {
                                $categoryQuery->where('name', 'like', $like)
                                    ->orWhere('aliases', 'like', $like)
                                    ->orWhereHas('searchTerms', fn ($terms) => $terms->where('term', 'like', $like));
                            });
                        }
                    });
                }
            })
            ->limit(60)
            ->get();

        $contentTokens = $this->contentTokens($searchTokens);
        $languageTokens = array_values(array_filter(
            $searchTokens,
            fn (string $token): bool => $this->isLanguageQualifier($token),
        ));
        $matched = $items
            ->map(fn (ChatCatalogItem $item): array => [
                'score' => $this->score($catalog, $item, $searchTokens, $weights),
                'token_hits' => $this->matchedTokenCount($catalog, $item, $searchTokens),
                'content_hits' => $this->matchedTokenCount($catalog, $item, $contentTokens),
                'language_hits' => $this->matchedTokenCount($catalog, $item, $languageTokens),
                'item' => $item,
            ])
            ->filter(fn (array $row): bool => $row['score'] >= 2);
        $bestContentHits = (int) $matched->max('content_hits');
        if ($bestContentHits > 0) {
            $matched = $matched->filter(fn (array $row): bool => $row['content_hits'] === $bestContentHits);
            if ($languageTokens !== [] && (int) $matched->max('language_hits') > 0) {
                $matched = $matched->filter(fn (array $row): bool => $row['language_hits'] > 0);
            }
        } else {
            $bestTokenHits = (int) $matched->max('token_hits');
            if ($bestTokenHits > 0) {
                $matched = $matched->filter(fn (array $row): bool => $row['token_hits'] === $bestTokenHits);
            }
        }
        $matched = $matched
            ->sortByDesc('score')
            ->values();

        $matchedItems = $matched->pluck('item');
        $sortPolicy = $catalog->sort_policy ?? ChatCatalogSortPolicy::Manual;
        if (! $catalog->discoveryEnabled('featured_items_enabled')
            && $sortPolicy === ChatCatalogSortPolicy::FeaturedFirst) {
            $sortPolicy = ChatCatalogSortPolicy::Manual;
        }
        $sortedItems = $this->sortService->sort(
            $matchedItems,
            $sortPolicy,
        );

        $ordered = [];
        foreach ($sortedItems as $item) {
            $row = $this->toPromptRow($catalog, $item, $forAi, $forAi ? $tokens : []);
            if ($row === []) {
                continue;
            }
            $ordered[] = $row;
            if (count($ordered) >= $limit) {
                break;
            }
        }

        return $ordered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listDiscoverableForPrompt(
        Workspace $workspace,
        ChatCatalog $catalog,
        int $limit,
        string $channel,
        ?string $itemKind,
        bool $forAi = false,
    ): array {
        unset($channel);

        $limit = max(1, min(20, $limit));

        $items = $this->discoverableItemsQuery($workspace, $catalog, $forAi)
            ->when($itemKind !== null, fn ($query) => $query->where('item_kind', $itemKind))
            ->limit(120)
            ->get();

        $sortPolicy = $catalog->sort_policy ?? ChatCatalogSortPolicy::Manual;
        if (! $catalog->discoveryEnabled('featured_items_enabled')
            && $sortPolicy === ChatCatalogSortPolicy::FeaturedFirst) {
            $sortPolicy = ChatCatalogSortPolicy::Manual;
        }

        $sortedItems = $this->sortService->sort($items, $sortPolicy);

        $ordered = [];
        foreach ($sortedItems as $item) {
            $row = $this->toPromptRow($catalog, $item, $forAi);
            if ($row === []) {
                continue;
            }
            $ordered[] = $row;
            if (count($ordered) >= $limit) {
                break;
            }
        }

        return $ordered;
    }

    /**
     * @return Builder<ChatCatalogItem>
     */
    private function discoverableItemsQuery(Workspace $workspace, ChatCatalog $catalog, bool $forAi = false)
    {
        return ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where(function ($query) use ($forAi): void {
                $query->whereNull('chat_catalog_category_id')
                    ->orWhereHas('category', fn ($category) => $category
                        ->where('is_active', true)
                        ->where('is_visible', true)
                        ->when(! $forAi, fn ($published) => $published->where('status', 'published')));
            })
            ->where(function ($builder): void {
                $builder->whereNull('publication_status')
                    ->orWhere('publication_status', 'published');
            })
            ->where(function ($builder): void {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->whereNull('archived_at')
            ->when(! $forAi && $catalog->discoveryEnabled('searchable'), fn ($q) => $q->where('is_searchable', true))
            ->when($forAi, fn ($q) => $q->where('is_searchable', true))
            ->with([
                'category:id,name,workspace_id,aliases',
                'category.searchTerms',
                'seller:id,workspace_id,name,display_name,status',
                'variants' => fn ($variantQuery) => $variantQuery
                    ->withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('is_active', true)
                    ->where('is_visible', true)
                    ->where('status', 'published')
                    ->with('structuredAttributes')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'structuredAttributes',
                'searchTerms',
                'digitalPools' => fn ($poolQuery) => $poolQuery->withCount('availableUnits'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Untrusted merchandising context for Inbox (not system instructions).
     *
     * @return array<string, mixed>
     */
    public function untrustedCommunicationContext(Workspace $workspace): array
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): array {
            $catalog = $this->catalogRoot->forWorkspace($workspace)->loadMissing('seller');
            $profile = $catalog->communicationProfile;

            return [
                '_trust' => 'untrusted_business_data',
                'catalog_status' => $catalog->status?->value,
                'catalog_open' => $this->availability->isCatalogOpenForCustomers($catalog),
                'localization' => [
                    'default_language' => $catalog->default_language,
                    'supported_languages' => $catalog->supported_languages ?? [],
                    'default_locale' => $catalog->default_locale,
                    'fallback_language' => $catalog->fallback_language,
                    'right_to_left' => $catalog->rtl_mode,
                ],
                'communication' => $profile?->toUntrustedAiPayload() ?? [],
                'customer_service_policy' => array_intersect_key(
                    $catalog->customer_service_policy ?? [],
                    array_flip([
                        'inquiry_mode',
                        'response_expectation_hours',
                        'out_of_hours_message',
                        'unavailable_product_message',
                        'unavailable_catalog_message',
                        'backorder_message',
                        'low_stock_message',
                        'support_contact_instructions',
                    ]),
                ),
                'seller' => [
                    'display_name' => $catalog->seller_public_display_name
                        ?: $catalog->seller?->display_name
                        ?: $catalog->seller?->name,
                    'support_contact' => $catalog->seller_support_contact ?? [],
                ],
                'customer_templates' => $this->sanitizer->customerTemplates($catalog->customer_templates ?? []),
            ];
        });
    }

    /**
     * @return list<string>
     */
    public function tokens(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        $gulf = $this->gulfArabic->interpret($query);
        $parts = $gulf['search_tokens'];
        $stop = [
            'هل', 'من', 'على', 'الى', 'او',
            'the', 'a', 'an', 'is', 'do', 'you', 'have', 'please', 'with', 'for', 'and', 'or',
            'يوجد', 'توجد', 'هليوجد', 'ممكن', 'اريد', 'نعم', 'احتاج', 'نحتاج',
            'مرحبا', 'هلا', 'اهلين', 'السلام', 'عليكم', 'صباح', 'مساء', 'هاي', 'hello', 'hi',
        ];

        $surfaceForms = $this->surfaceForms($query);

        $tokens = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '' || in_array($token, $stop, true)) {
                continue;
            }
            if (mb_strlen($token) < 2 && ! preg_match('/^[a-z0-9]$/u', $token)) {
                continue;
            }
            foreach ($surfaceForms[$token] ?? [] as $surface) {
                $tokens[] = $surface;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Customer spellings keyed by folded form, so "حذاء" still matches stored "حذاء"
     * even though folding turns it into "حذاا".
     *
     * @return array<string, list<string>>
     */
    private function surfaceForms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $forms = [];
        foreach ($words as $word) {
            $surface = preg_replace('/\p{M}+/u', '', trim((string) $word)) ?? '';
            if ($surface === '') {
                continue;
            }
            $folded = $this->normalizer->normalize($surface);
            if ($folded !== $surface) {
                $forms[$folded][] = $surface;
            }
        }

        return $forms;
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, int>  $weights
     */
    private function score(ChatCatalog $catalog, ChatCatalogItem $item, array $tokens, array $weights): int
    {
        $nameWeight = (int) ($weights['exact_name'] ?? 3);
        $skuWeight = (int) ($weights['sku'] ?? 2);
        $aliasWeight = (int) ($weights['alias'] ?? 2);
        $tagWeight = (int) ($weights['tag'] ?? 1);
        $categoryWeight = (int) ($weights['category'] ?? 1);
        $descriptionWeight = (int) ($weights['description'] ?? 1);

        $haystacks = [
            [$nameWeight, mb_strtolower((string) $item->name)],
            [1, mb_strtolower(json_encode($item->attributes ?? [], JSON_UNESCAPED_UNICODE) ?: '')],
        ];
        if ($catalog->discoveryEnabled('sku_search')) {
            $haystacks[] = [$skuWeight, mb_strtolower((string) $item->sku)];
        }
        if ($catalog->discoveryEnabled('alias_search')) {
            $haystacks[] = [$aliasWeight, mb_strtolower(json_encode($item->aliases ?? [], JSON_UNESCAPED_UNICODE) ?: '')];
        }
        if ($catalog->discoveryEnabled('synonym_search')) {
            $haystacks[] = [$aliasWeight, mb_strtolower(json_encode($item->search_phrases ?? [], JSON_UNESCAPED_UNICODE) ?: '')];
        }
        if ($catalog->discoveryEnabled('tag_search')) {
            $haystacks[] = [$tagWeight, mb_strtolower(json_encode($item->tags ?? [], JSON_UNESCAPED_UNICODE) ?: '')];
        }
        foreach ($item->structuredAttributes as $attribute) {
            if (! $this->productTemplateValidation->isAttributeSearchable($item, (string) $attribute->attribute_key)) {
                continue;
            }
            $haystacks[] = [2, mb_strtolower($attribute->attribute_key.' '.$attribute->attribute_value)];
        }

        if ($catalog->discoveryEnabled('description_search')) {
            $haystacks[] = [$descriptionWeight, mb_strtolower(implode(' ', [
                (string) $item->description,
                (string) $item->short_description,
                (string) $item->customer_description,
                (string) $item->usage_instructions,
            ]))];
        }
        if ($catalog->discoveryEnabled('brand_search')) {
            $haystacks[] = [max(2, $descriptionWeight), mb_strtolower((string) $item->brand)];
        }
        if ($catalog->discoveryEnabled('category_discovery') && $item->category) {
            $haystacks[] = [$categoryWeight, mb_strtolower($item->category->name.' '.json_encode($item->category->aliases ?? [], JSON_UNESCAPED_UNICODE))];
            foreach ($item->category->searchTerms as $term) {
                $haystacks[] = [$categoryWeight, mb_strtolower($term->term)];
            }
        }

        foreach ($item->searchTerms as $term) {
            if ($term->term_type === 'name') {
                continue;
            }
            if (($term->term_type === 'tag' && ! $catalog->discoveryEnabled('tag_search'))
                || ($term->term_type === 'alias' && ! $catalog->discoveryEnabled('alias_search'))
                || ($term->term_type === 'synonym' && ! $catalog->discoveryEnabled('synonym_search'))) {
                continue;
            }
            $weight = match ($term->term_type) {
                'alias', 'synonym' => $aliasWeight,
                'tag' => $tagWeight,
                default => 1,
            };
            $haystacks[] = [$weight, mb_strtolower($term->term)];
        }
        if ($catalog->discoveryEnabled('variant_discovery')) {
            foreach ($item->variants as $variant) {
                $haystacks[] = [$nameWeight, mb_strtolower((string) $variant->name)];
                if ($catalog->discoveryEnabled('sku_search')) {
                    $haystacks[] = [$skuWeight, mb_strtolower((string) $variant->sku)];
                }
                $haystacks[] = [1, mb_strtolower(json_encode($variant->attributes ?? [], JSON_UNESCAPED_UNICODE) ?: '')];
                foreach ($variant->structuredAttributes as $attribute) {
                    if (! $this->productTemplateValidation->isAttributeSearchable($item, (string) $attribute->attribute_key)) {
                        continue;
                    }
                    $haystacks[] = [2, mb_strtolower($attribute->attribute_key.' '.$attribute->attribute_value)];
                }
            }
        }

        $haystacks = array_map(
            fn (array $row): array => [$row[0], $this->normalizer->normalize($row[1])],
            $haystacks,
        );
        $foldedTokens = array_values(array_unique(array_filter(array_map(
            fn (string $token): string => $this->normalizer->normalize($token),
            $tokens,
        ))));

        $score = 0;
        foreach ($foldedTokens as $token) {
            foreach ($haystacks as [$weight, $haystack]) {
                if ($haystack !== '' && mb_strpos($haystack, $token) !== false) {
                    $score += $weight;
                }
            }

            foreach ($item->variants as $variant) {
                $variantHay = $this->normalizer->normalize(
                    $variant->name.' '.$variant->sku.' '
                    .json_encode($variant->attributes ?? [], JSON_UNESCAPED_UNICODE),
                );
                if (mb_strpos($variantHay, $token) !== false) {
                    $score += 2;
                }
            }
        }

        return $score;
    }

    /**
     * Stored catalog text may use ة where the customer wrote ه, or the reverse.
     * These forms are for SQL only so one word is not counted twice.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function orthographicSearchTokens(array $tokens): array
    {
        $forms = $tokens;
        foreach ($tokens as $token) {
            if (str_contains($token, 'ه')) {
                $forms[] = str_replace('ه', 'ة', $token);
            }
            if (str_contains($token, 'ة')) {
                $forms[] = str_replace('ة', 'ه', $token);
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * Language and generic words such as "كتاب" choose a copy or a kind.
     * They must not outrank the product the customer actually named.
     *
     * @param  list<string>  $searchTokens
     * @return list<string>
     */
    private function contentTokens(array $searchTokens): array
    {
        $content = array_values(array_filter(
            $searchTokens,
            fn (string $token): bool => ! $this->isLanguageQualifier($token),
        ));
        $specific = array_values(array_filter(
            $content,
            fn (string $token): bool => ! $this->isGenericProductWord($token),
        ));

        return $specific !== [] ? $specific : $content;
    }

    private function isLanguageQualifier(string $token): bool
    {
        $folded = $this->normalizer->normalize($token);
        $folded = preg_replace('/^(ال|بال|لل)/u', '', $folded) ?? $folded;

        return str_starts_with($folded, 'عربي')
            || str_starts_with($folded, 'انجليزي')
            || in_array($folded, ['arabic', 'english'], true);
    }

    private function isGenericProductWord(string $token): bool
    {
        $folded = $this->normalizer->normalize($token);
        $folded = preg_replace('/^(ال|بال|لل)/u', '', $folded) ?? $folded;

        return in_array($folded, [
            'كتاب', 'كتب', 'نسخه', 'نسخ', 'منتج', 'منتجات', 'خدمه', 'دوره', 'book', 'product',
        ], true);
    }

    /**
     * How many query tokens appear on the product or one of its variants.
     * Results that miss a distinguishing token are dropped when another result matches more.
     *
     * @param  list<string>  $tokens
     */
    private function matchedTokenCount(ChatCatalog $catalog, ChatCatalogItem $item, array $tokens): int
    {
        $hits = 0;
        foreach ($tokens as $token) {
            $needle = $this->normalizer->normalize($token);
            if (mb_strlen($needle) < 2) {
                continue;
            }
            if ($this->score($catalog, $item, [$token], []) > 0) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * AI-safe single product row for public catalog chat context (untrusted data).
     *
     * @return array<string, mixed>
     */
    public function promptRowForPublicCatalogItem(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant = null,
    ): array {
        return app(WorkspaceContext::class)->runFor(
            $workspace,
            function () use ($workspace, $item, $variant): array {
                $catalog = $this->catalogRoot->forWorkspace($workspace);
                $item->loadMissing([
                    'category:id,name,workspace_id,aliases',
                    'category.searchTerms',
                    'seller:id,workspace_id,name,display_name,status',
                    'variants' => fn ($variantQuery) => $variantQuery
                        ->withoutGlobalScopes()
                        ->where('workspace_id', $workspace->id)
                        ->where('is_active', true)
                        ->where('is_visible', true)
                        ->where('status', 'published')
                        ->with('structuredAttributes')
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                    'structuredAttributes',
                    'productTemplate.fields',
                    'media',
                    'digitalPools' => fn ($poolQuery) => $poolQuery->withCount('availableUnits'),
                ]);

                $row = $this->toPromptRow($catalog, $item);
                if ($row === []) {
                    return [];
                }

                if ($variant !== null) {
                    $variantRow = $this->variantRow($catalog, $item, $variant);
                    if ($variantRow !== []) {
                        $row['selected_variant'] = $variantRow;
                    }
                }

                return $row;
            },
        );
    }

    /**
     * @param  list<string>  $queryTokens
     * @return array<string, mixed>
     */
    private function toPromptRow(ChatCatalog $catalog, ChatCatalogItem $item, bool $forAi = false, array $queryTokens = []): array
    {
        if (! $item->isDiscoverable()) {
            return [];
        }

        $availability = $this->inventory->forItem($catalog, $item);
        $catalogIsOpen = $this->availability->isCatalogOpenForCustomers($catalog);
        if (! $forAi && ($catalog->status?->value === 'paused' || ! $catalogIsOpen)) {
            $availability = [
                'available' => false,
                'label' => $catalog->status?->value === 'paused' ? 'catalog_paused' : 'catalog_closed',
                'quantity' => $availability['quantity'],
                'policy' => $availability['policy'],
            ];
        }
        $behavior = $catalog->unavailable_item_behavior ?? ChatCatalogUnavailableBehavior::ShowUnavailable;

        if ($behavior === ChatCatalogUnavailableBehavior::Hide
            && ! $availability['available']
            && $catalog->status?->value !== 'paused'
            && $catalogIsOpen) {
            return [];
        }

        $display = is_array($catalog->display_settings) ? $catalog->display_settings : [];
        $price = $this->prices->forItem($catalog, $item);

        $row = [
            'catalog_item_id' => $item->id,
            'name' => $item->name,
            'description' => $item->customer_description ?? $item->short_description ?? $item->description,
            'sku' => ($display['show_sku'] ?? false) ? $item->sku : null,
            'barcode' => null,
            'brand' => ($display['show_brand'] ?? true) ? $item->brand : null,
            'item_kind' => $item->item_kind,
            'tangibility' => $item->tangibility,
            'classification' => $item->classification?->value,
            'highlights' => $item->highlights ?? [],
            'specifications' => $this->safeSpecifications($item),
            'warnings' => $item->warnings,
            'terms' => $item->terms,
            'merchandising' => [
                'featured' => $catalog->discoveryEnabled('featured_items_enabled') && $item->is_featured,
                'bestseller' => $item->is_bestseller,
                'new' => $item->is_new,
                'recommended' => $item->is_recommended,
            ],
            'price' => $price['amount'],
            'display_price' => $price['formatted'],
            'price_display' => $price['is_from_price'] ? 'from_price' : 'standard',
            'currency' => $price['currency'],
            'compare_at_price' => null,
            'sale_price' => null,
            'tax_presentation' => $price['tax_presentation'],
            'inquiry_mode' => $catalog->customer_service_policy['inquiry_mode'] ?? 'enabled',
            'availability' => $availability,
            'category' => $catalog->discoveryEnabled('category_discovery') ? $item->category?->name : null,
            'seller_display_name' => (int) $item->commerce_seller_id === (int) $catalog->commerce_seller_id
                ? ($catalog->seller_public_display_name ?: $item->seller?->display_name ?: $item->seller?->name)
                : ($item->seller?->display_name ?: $item->seller?->name),
            'attributes' => $this->safeAttributes($item),
            'policies' => $item->policies ?? [],
            'ai_safe_profile' => $this->safeAiProfile($item),
        ];

        if ($display['show_stock'] ?? true) {
            $row['stock'] = $availability['quantity'];
        }
        if ($availability['label'] === 'low_stock') {
            $row['availability_message'] = $catalog->customer_service_policy['low_stock_message'] ?? null;
        } elseif ($availability['label'] === 'backorder') {
            $row['availability_message'] = $catalog->customer_service_policy['backorder_message'] ?? null;
        } elseif ($availability['label'] === 'catalog_closed') {
            $row['availability_message'] = $catalog->customer_service_policy['out_of_hours_message']
                ?? $catalog->customer_service_policy['unavailable_catalog_message']
                ?? null;
        } elseif (! $availability['available']) {
            $row['availability_message'] = $catalog->customer_service_policy['unavailable_product_message'] ?? null;
        }

        if (($display['show_variants'] ?? true) && $catalog->discoveryEnabled('variant_discovery')) {
            $row['variants'] = $item->variants
                ->map(fn (ChatCatalogItemVariant $variant): array => $this->variantRow($catalog, $item, $variant))
                ->filter(fn (array $v): bool => $v !== [])
                ->values()
                ->all();
        } else {
            $row['variants'] = [];
        }

        if ($forAi && $queryTokens !== [] && $row['variants'] !== []) {
            $row['variants'] = array_map(function (array $variant) use ($queryTokens): array {
                $variant['matches_question'] = $this->variantMatchesQuestion($variant, $queryTokens);

                return $variant;
            }, $row['variants']);
            usort($row['variants'], function (array $left, array $right): int {
                return ((int) ($right['matches_question'] ?? false)) <=> ((int) ($left['matches_question'] ?? false));
            });
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function variantRow(ChatCatalog $catalog, ChatCatalogItem $item, ChatCatalogItemVariant $variant): array
    {
        $availability = $this->inventory->forVariant($catalog, $variant);
        $display = is_array($catalog->display_settings) ? $catalog->display_settings : [];
        $price = $this->prices->forVariant($catalog, $variant);

        $row = [
            'id' => (int) $variant->id,
            'name' => $variant->name,
            'sku' => ($display['show_sku'] ?? false) ? $variant->sku : null,
            'price' => $price['amount'],
            'display_price' => $price['formatted'],
            'currency' => $price['currency'],
            'compare_at_price' => null,
            'sale_price' => null,
            'availability' => $availability,
            'attributes' => $this->safeVariantAttributes($item, $variant),
        ];
        if ($display['show_stock'] ?? true) {
            $row['stock'] = $availability['quantity'];
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeAttributes(ChatCatalogItem $item): array
    {
        $structured = $item->structuredAttributes
            ->filter(fn ($row): bool => $this->isCustomerSafeAttributeKey($row->attribute_key))
            ->filter(fn ($row): bool => $this->productTemplateValidation->isAttributeCustomerVisible($item, (string) $row->attribute_key))
            ->mapWithKeys(fn ($row): array => [$row->attribute_key => $row->attribute_value])
            ->all();
        $flexible = collect($item->attributes ?? [])
            ->filter(fn ($value, $key): bool => is_string($key) && $this->isCustomerSafeAttributeKey($key))
            ->all();

        return array_merge($flexible, $structured);
    }

    private function isCustomerSafeAttributeKey(string $key): bool
    {
        return preg_match('/(?:cost|supplier|internal|private|secret|credential|token|password|permission)/i', $key) !== 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeSpecifications(ChatCatalogItem $item): array
    {
        return collect($item->specifications ?? [])
            ->filter(fn ($value, $key): bool => is_string($key) && $this->isCustomerSafeAttributeKey($key))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function safeVariantAttributes(ChatCatalogItem $item, ChatCatalogItemVariant $variant): array
    {
        $structured = $variant->structuredAttributes
            ->filter(fn ($row): bool => $this->isCustomerSafeAttributeKey($row->attribute_key))
            ->filter(fn ($row): bool => $this->productTemplateValidation->isAttributeCustomerVisible($item, (string) $row->attribute_key))
            ->mapWithKeys(fn ($row): array => [$row->attribute_key => $row->attribute_value])
            ->all();
        $flexible = collect($variant->attributes ?? [])
            ->filter(fn ($value, $key): bool => is_string($key) && $this->isCustomerSafeAttributeKey($key))
            ->all();

        return array_merge($flexible, $structured);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeAiProfile(ChatCatalogItem $item): array
    {
        $profile = is_array($item->ai_safe_profile) ? $item->ai_safe_profile : [];

        unset($profile['system_prompt'], $profile['tool_instructions'], $profile['credentials']);

        return [
            '_trust' => 'untrusted_catalog_data',
            ...$profile,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
