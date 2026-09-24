<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogSortPolicy;
use App\Models\ChatCatalogItem;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ChatCatalogSortService
{
    /**
     * Applies the same stable policy used by AI retrieval to database-backed
     * catalog browsing, avoiding a full catalog load for public pages.
     *
     * @param  Builder<ChatCatalogItem>  $query
     * @return Builder<ChatCatalogItem>
     */
    public function applyToQuery(Builder $query, ChatCatalogSortPolicy $policy): Builder
    {
        return match ($policy) {
            ChatCatalogSortPolicy::FeaturedFirst => $query
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->orderBy('id'),
            ChatCatalogSortPolicy::Newest => $query
                ->orderByDesc('published_at')
                ->orderByDesc('id'),
            ChatCatalogSortPolicy::Oldest => $query
                ->orderBy('published_at')
                ->orderBy('id'),
            ChatCatalogSortPolicy::PriceLowHigh => $query
                ->orderByRaw('COALESCE(sale_price, price) asc')
                ->orderBy('id'),
            ChatCatalogSortPolicy::PriceHighLow => $query
                ->orderByRaw('COALESCE(sale_price, price) desc')
                ->orderBy('id'),
            ChatCatalogSortPolicy::NameAsc => $query->orderBy('name')->orderBy('id'),
            ChatCatalogSortPolicy::NameDesc => $query->orderByDesc('name')->orderBy('id'),
            ChatCatalogSortPolicy::AvailabilityFirst => $query
                ->orderByRaw('CASE WHEN stock IS NULL OR stock > 0 THEN 1 ELSE 0 END desc')
                ->orderBy('sort_order')
                ->orderBy('id'),
            ChatCatalogSortPolicy::Manual => $query
                ->orderBy('sort_order')
                ->orderBy('name')
                ->orderBy('id'),
        };
    }

    /**
     * @param  Collection<int, ChatCatalogItem>  $items
     * @return Collection<int, ChatCatalogItem>
     */
    public function sort(Collection $items, ChatCatalogSortPolicy $policy): Collection
    {
        return match ($policy) {
            ChatCatalogSortPolicy::FeaturedFirst => $items->sortBy(fn (ChatCatalogItem $i): string => sprintf(
                '%01d-%010d-%010d',
                $i->is_featured ? 0 : 1,
                $i->sort_order,
                $i->id,
            ))
                ->values(),
            ChatCatalogSortPolicy::Newest => $items->sortByDesc(fn (ChatCatalogItem $i): string => sprintf(
                '%020d-%010d',
                ($i->published_at ?? $i->created_at)->getTimestamp(),
                $i->id,
            ))->values(),
            ChatCatalogSortPolicy::Oldest => $items->sortBy(fn (ChatCatalogItem $i): string => sprintf(
                '%020d-%010d',
                ($i->published_at ?? $i->created_at)->getTimestamp(),
                $i->id,
            ))->values(),
            ChatCatalogSortPolicy::PriceLowHigh => $items->sortBy(fn (ChatCatalogItem $i): string => sprintf(
                '%020d-%010d',
                Money::minor($i->effectiveSalePrice() ?? $i->price),
                $i->id,
            ))->values(),
            ChatCatalogSortPolicy::PriceHighLow => $items->sortByDesc(fn (ChatCatalogItem $i): string => sprintf(
                '%020d-%010d',
                Money::minor($i->effectiveSalePrice() ?? $i->price),
                $i->id,
            ))->values(),
            ChatCatalogSortPolicy::NameAsc => $items->sortBy(fn (ChatCatalogItem $i): string => mb_strtolower($i->name).'-'.sprintf('%010d', $i->id))->values(),
            ChatCatalogSortPolicy::NameDesc => $items->sortByDesc(fn (ChatCatalogItem $i): string => mb_strtolower($i->name).'-'.sprintf('%010d', $i->id))->values(),
            ChatCatalogSortPolicy::AvailabilityFirst => $items->sortByDesc(fn (ChatCatalogItem $i): string => sprintf(
                '%01d-%010d',
                $this->availabilityScore($i),
                $i->id,
            ))->values(),
            ChatCatalogSortPolicy::Manual => $items->sortBy([
                fn (ChatCatalogItem $a, ChatCatalogItem $b) => $a->sort_order <=> $b->sort_order,
                fn (ChatCatalogItem $a, ChatCatalogItem $b) => strcmp($a->name, $b->name),
                fn (ChatCatalogItem $a, ChatCatalogItem $b) => $a->id <=> $b->id,
            ])->values(),
        };
    }

    private function availabilityScore(ChatCatalogItem $item): int
    {
        if ($item->inventory_policy?->value === 'unlimited') {
            return 2;
        }
        if ($item->stock === null || $item->stock > 0) {
            return 1;
        }

        return 0;
    }
}
