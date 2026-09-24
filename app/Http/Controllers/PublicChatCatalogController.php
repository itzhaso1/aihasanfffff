<?php

namespace App\Http\Controllers;

use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Enums\ChatCatalog\ChatCatalogSortPolicy;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Services\Chat\CatalogAvailabilityService;
use App\Services\Chat\ChatCatalogInventoryPresenter;
use App\Services\Chat\ChatCatalogPricePresenter;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogSortService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only web discovery. It deliberately contains no checkout, cart,
 * payment, or digital-delivery path.
 */
class PublicChatCatalogController extends Controller
{
    public function __construct(
        private readonly ChatCatalogRootService $catalogs,
        private readonly CatalogAvailabilityService $availability,
        private readonly ChatCatalogSortService $sort,
        private readonly ChatCatalogInventoryPresenter $inventory,
        private readonly ChatCatalogPricePresenter $prices,
    ) {}

    public function show(Request $request, string $workspaceSlug, ?string $catalogSlug = null): View
    {
        $workspace = Workspace::query()->where('slug', $workspaceSlug)->firstOrFail();

        return app(WorkspaceContext::class)->runFor(
            $workspace,
            fn (): View => $this->showForWorkspace($request, $workspace, $catalogSlug),
        );
    }

    private function showForWorkspace(Request $request, Workspace $workspace, ?string $catalogSlug): View
    {
        $catalog = $this->catalogs->forWorkspace($workspace)->load(['communicationProfile', 'seller']);
        abort_if($catalogSlug !== null && $catalogSlug !== $catalog->slug, 404);
        abort_unless($catalog->channelEnabled('web'), 404);
        abort_unless($this->availability->customerDiscoveryAllowed($catalog), 404);
        abort_unless($catalog->discoveryEnabled('discoverable'), 404);
        $isOpen = $this->availability->isCatalogOpenForCustomers($catalog);
        $selectedCategory = null;
        $categorySlug = trim((string) $request->query('category', ''));
        if ($categorySlug !== '') {
            $selectedCategory = ChatCatalogCategory::query()
                ->where('slug', $categorySlug)
                ->where('is_active', true)
                ->where('is_visible', true)
                ->where('status', 'published')
                ->firstOrFail();
        }

        $items = ChatCatalogItem::queryForWorkspace($workspace->id)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where('is_searchable', true)
            ->where(function ($query): void {
                $query->whereNull('chat_catalog_category_id')
                    ->orWhereHas('category', fn ($category) => $category
                        ->where('is_active', true)
                        ->where('is_visible', true)
                        ->where('status', 'published'));
            })
            ->where(function ($query): void {
                $query->whereNull('publication_status')
                    ->orWhere('publication_status', 'published');
            })
            ->where(function ($query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->whereNull('archived_at')
            ->with([
                'category:id,workspace_id,name,status,is_visible,is_active',
                'variants' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_visible', true)
                    ->where('status', 'published')
                    ->with('structuredAttributes')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'media' => fn ($query) => $query
                    ->where('is_visible', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'digitalPools' => fn ($query) => $query->withCount('availableUnits'),
            ]);
        if ($selectedCategory) {
            $items->where('chat_catalog_category_id', $selectedCategory->id);
        }

        if ($catalog->unavailable_item_behavior === ChatCatalogUnavailableBehavior::Hide
            && $catalog->status !== ChatCatalogOperatingStatus::Paused
            && $isOpen) {
            $items->where(function ($query): void {
                $query->whereIn('inventory_policy', ['not_tracked', 'unlimited', 'on_request', 'backorder'])
                    ->orWhere(function ($finiteStock): void {
                        $finiteStock
                            ->where(function ($policy): void {
                                $policy->whereNull('inventory_policy')
                                    ->orWhereIn('inventory_policy', ['finite_stock', 'file_asset', 'service_capacity']);
                            })
                            ->where(function ($stock): void {
                                $stock->whereNull('stock')->orWhere('stock', '>', 0);
                            });
                    })
                    ->orWhere(function ($digitalPool): void {
                        $digitalPool->where('inventory_policy', 'digital_pool')
                            ->whereHas('digitalPools', fn ($pools) => $pools->whereHas('availableUnits'));
                    });
            });
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '' && $catalog->discoveryEnabled('searchable')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $items->where(function ($query) use ($like, $catalog): void {
                $query->where('name', 'like', $like);
                if ($catalog->discoveryEnabled('description_search')) {
                    $query->orWhere('description', 'like', $like)
                        ->orWhere('short_description', 'like', $like)
                        ->orWhere('customer_description', 'like', $like);
                }
                if ($catalog->discoveryEnabled('brand_search')) {
                    $query->orWhere('brand', 'like', $like);
                }
                if ($catalog->discoveryEnabled('sku_search')) {
                    $query->orWhere('sku', 'like', $like);
                }
                if ($catalog->discoveryEnabled('barcode_search')) {
                    $query->orWhere('barcode', 'like', $like);
                }
                if ($catalog->discoveryEnabled('tag_search')) {
                    $query->orWhereHas('searchTerms', fn ($terms) => $terms
                        ->where('term_type', 'tag')
                        ->where('term', 'like', $like));
                }
                if ($catalog->discoveryEnabled('alias_search')) {
                    $query->orWhereHas('searchTerms', fn ($terms) => $terms
                        ->where('term_type', 'alias')
                        ->where('term', 'like', $like));
                }
                if ($catalog->discoveryEnabled('synonym_search')) {
                    $query->orWhereHas('searchTerms', fn ($terms) => $terms
                        ->where('term_type', 'synonym')
                        ->where('term', 'like', $like));
                }
                $query->orWhereHas('structuredAttributes', fn ($attributes) => $attributes
                    ->where('attribute_key', 'like', $like)
                    ->orWhere('attribute_value', 'like', $like));
                if ($catalog->discoveryEnabled('category_discovery')) {
                    $query->orWhereHas('category', fn ($category) => $category
                        ->where('name', 'like', $like)
                        ->orWhereHas('searchTerms', fn ($terms) => $terms->where('term', 'like', $like)));
                }
                if ($catalog->discoveryEnabled('variant_discovery')) {
                    $query->orWhereHas('variants', function ($variants) use ($like, $catalog): void {
                        $variants->where('is_active', true)->where('is_visible', true)->where('status', 'published')
                            ->where(function ($variant) use ($like, $catalog): void {
                                $variant->where('name', 'like', $like);
                                $variant->orWhereHas('structuredAttributes', fn ($attributes) => $attributes
                                    ->where('attribute_key', 'like', $like)
                                    ->orWhere('attribute_value', 'like', $like));
                                if ($catalog->discoveryEnabled('sku_search')) {
                                    $variant->orWhere('sku', 'like', $like);
                                }
                                if ($catalog->discoveryEnabled('barcode_search')) {
                                    $variant->orWhere('barcode', 'like', $like);
                                }
                            });
                    });
                }
            });
        }

        $sortPolicy = $catalog->sort_policy;
        if (! $catalog->discoveryEnabled('featured_items_enabled')
            && $sortPolicy === ChatCatalogSortPolicy::FeaturedFirst) {
            $sortPolicy = ChatCatalogSortPolicy::Manual;
        }
        $this->sort->applyToQuery($items, $sortPolicy);
        $items = $items->paginate(36)->through(function (ChatCatalogItem $item) use ($catalog, $isOpen): ChatCatalogItem {
            $availability = $this->inventory->forItem($catalog, $item);
            if (! $isOpen) {
                $availability = [
                    'available' => false,
                    'label' => 'catalog_closed',
                    'quantity' => $availability['quantity'],
                    'policy' => $availability['policy'],
                ];
            }
            $item->setAttribute('customer_availability', $availability);
            $item->setAttribute('customer_price', $this->prices->forItem($catalog, $item));

            return $item;
        });

        return view('public.chat-catalog.show', [
            'workspace' => $workspace,
            'catalog' => $catalog,
            'items' => $items,
            'categories' => $catalog->discoveryEnabled('category_discovery')
                ? ChatCatalogCategory::query()
                    ->where('is_active', true)
                    ->where('is_visible', true)
                    ->where('status', 'published')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->limit(30)
                    ->get()
                : collect(),
            'selectedCategory' => $selectedCategory,
            'isOpen' => $isOpen,
            'isPaused' => $catalog->status === ChatCatalogOperatingStatus::Paused,
        ]);
    }
}
