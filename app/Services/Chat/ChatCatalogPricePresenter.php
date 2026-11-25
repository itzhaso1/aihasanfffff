<?php

namespace App\Services\Chat;

use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Support\Money\Money;

/**
 * Applies catalog presentation settings without changing authoritative prices.
 */
final class ChatCatalogPricePresenter
{
    /**
     * @return array{
     *     amount:string,
     *     formatted:string,
     *     currency:string,
     *     is_from_price:bool,
     *     sale_amount:?string,
     *     compare_at_amount:?string,
     *     tax_presentation:?string
     * }
     */
    public function forItem(ChatCatalog $catalog, ChatCatalogItem $item): array
    {
        $display = is_array($catalog->display_settings) ? $catalog->display_settings : [];
        $amount = (string) $item->price;
        $isFromPrice = false;

        if (($display['price_display'] ?? 'standard') === 'from_price' && $item->relationLoaded('variants')) {
            $variantPrices = $item->variants
                ->filter(fn (ChatCatalogItemVariant $variant): bool => $variant->is_active && $variant->is_visible)
                ->map(fn (ChatCatalogItemVariant $variant): string => $this->effectiveVariantPrice($variant, $amount))
                ->map(fn (string $price): int => Money::minor($price));

            if ($variantPrices->isNotEmpty()) {
                $amount = Money::fromMinor($variantPrices->min());
                $isFromPrice = true;
            }
        }

        return [
            'amount' => Money::of($amount),
            'formatted' => $this->format(Money::minor($amount), (int) ($display['decimal_places'] ?? 2)),
            'currency' => $item->currency ?: $catalog->default_currency,
            'is_from_price' => $isFromPrice,
            'sale_amount' => null,
            'compare_at_amount' => null,
            'tax_presentation' => $this->taxPresentation($item->tax_display_behavior, $display),
        ];
    }

    /**
     * @return array{amount:?string,formatted:?string,currency:?string,sale_amount:?string,compare_at_amount:?string}
     */
    public function forVariant(ChatCatalog $catalog, ChatCatalogItemVariant $variant): array
    {
        $display = is_array($catalog->display_settings) ? $catalog->display_settings : [];
        $price = $variant->price === null ? null : $this->effectiveVariantPrice($variant, (string) $variant->price);

        return [
            'amount' => $price === null ? null : Money::of($price),
            'formatted' => $price === null ? null : $this->format(Money::minor($price), (int) ($display['decimal_places'] ?? 2)),
            'currency' => $variant->currency ?: $catalog->default_currency,
            'sale_amount' => null,
            'compare_at_amount' => null,
        ];
    }

    /**
     * AI-facing price view exposing active offers, original/compare-at price, and discount %
     * using exact integer minor-unit calculations (never altering storefront public rows).
     *
     * @return array{
     *     amount:string,
     *     base_amount:string,
     *     formatted:string,
     *     currency:string,
     *     is_from_price:bool,
     *     has_active_offer:bool,
     *     sale_amount:?string,
     *     compare_at_amount:?string,
     *     discount_percent:?int,
     *     price_valid_from:?string,
     *     price_valid_until:?string,
     *     tax_presentation:?string
     * }
     */
    public function forAiItem(ChatCatalog $catalog, ChatCatalogItem $item): array
    {
        $base = $this->forItem($catalog, $item);
        $display = is_array($catalog->display_settings) ? $catalog->display_settings : [];

        $baseAmount = Money::of((string) ($item->price ?? $base['amount']));
        $baseMinor = Money::minor($baseAmount);

        $saleActive = $this->isSaleWindowActive($item)
            && $item->sale_price !== null
            && Money::minor((string) $item->sale_price) > 0
            && Money::minor((string) $item->sale_price) < $baseMinor;

        $saleAmount = $saleActive ? Money::of((string) $item->sale_price) : null;
        $compareAt = null;
        if ($item->compare_at_price !== null && Money::minor((string) $item->compare_at_price) > 0) {
            $compareAt = Money::of((string) $item->compare_at_price);
        } elseif ($saleActive) {
            $compareAt = $baseAmount;
        }

        $effectiveAmount = $saleActive ? (string) $saleAmount : $base['amount'];
        $effectiveMinor = Money::minor($effectiveAmount);

        $referenceMinor = $compareAt !== null ? Money::minor($compareAt) : $baseMinor;
        $hasActiveOffer = ($saleActive || ($compareAt !== null && $referenceMinor > $effectiveMinor))
            && $referenceMinor > $effectiveMinor;

        $discountPercent = $hasActiveOffer && $referenceMinor > 0
            ? (int) round((($referenceMinor - $effectiveMinor) * 100) / $referenceMinor)
            : null;

        return [
            'amount' => $effectiveAmount,
            'base_amount' => $baseAmount,
            'formatted' => $this->format($effectiveMinor, (int) ($display['decimal_places'] ?? 2)),
            'currency' => $base['currency'],
            'is_from_price' => $base['is_from_price'],
            'has_active_offer' => $hasActiveOffer,
            'sale_amount' => $saleAmount,
            'compare_at_amount' => $hasActiveOffer ? ($compareAt ?? $baseAmount) : $compareAt,
            'discount_percent' => $discountPercent,
            'price_valid_from' => $item->price_valid_from?->toIso8601String(),
            'price_valid_until' => $item->price_valid_until?->toIso8601String(),
            'tax_presentation' => $base['tax_presentation'],
        ];
    }

    /**
     * @return array{
     *     amount:?string,
     *     base_amount:?string,
     *     formatted:?string,
     *     currency:?string,
     *     has_active_offer:bool,
     *     sale_amount:?string,
     *     compare_at_amount:?string,
     *     discount_percent:?int
     * }
     */
    public function forAiVariant(ChatCatalog $catalog, ChatCatalogItem $item, ChatCatalogItemVariant $variant): array
    {
        $display = is_array($catalog->display_settings) ? $catalog->display_settings : [];
        $rawBase = $variant->price !== null ? (string) $variant->price : (string) ($item->price ?? '0.00');
        $baseAmount = Money::of($rawBase);
        $baseMinor = Money::minor($baseAmount);

        $saleActive = $variant->sale_price !== null
            && Money::minor((string) $variant->sale_price) > 0
            && Money::minor((string) $variant->sale_price) < $baseMinor;

        $saleAmount = $saleActive ? Money::of((string) $variant->sale_price) : null;
        $compareAt = null;
        if ($variant->compare_at_price !== null && Money::minor((string) $variant->compare_at_price) > 0) {
            $compareAt = Money::of((string) $variant->compare_at_price);
        } elseif ($saleActive) {
            $compareAt = $baseAmount;
        }

        $effectiveAmount = $saleActive ? (string) $saleAmount : $baseAmount;
        $effectiveMinor = Money::minor($effectiveAmount);
        $referenceMinor = $compareAt !== null ? Money::minor($compareAt) : $baseMinor;
        $hasOffer = $referenceMinor > $effectiveMinor;

        return [
            'amount' => $effectiveAmount,
            'base_amount' => $baseAmount,
            'formatted' => $this->format($effectiveMinor, (int) ($display['decimal_places'] ?? 2)),
            'currency' => $variant->currency ?: ($item->currency ?: $catalog->default_currency),
            'has_active_offer' => $hasOffer,
            'sale_amount' => $saleAmount,
            'compare_at_amount' => $compareAt,
            'discount_percent' => $hasOffer && $referenceMinor > 0
                ? (int) round((($referenceMinor - $effectiveMinor) * 100) / $referenceMinor)
                : null,
        ];
    }

    private function isSaleWindowActive(ChatCatalogItem $item): bool
    {
        $now = now();
        if ($item->price_valid_from !== null && $item->price_valid_from->isFuture()) {
            return false;
        }
        if ($item->price_valid_until !== null && $item->price_valid_until->isPast()) {
            return false;
        }

        return true;
    }

    private function effectiveVariantPrice(ChatCatalogItemVariant $variant, string $fallback): string
    {
        return $variant->price !== null ? (string) $variant->price : $fallback;
    }

    private function format(int $minor, int $decimalPlaces): string
    {
        $decimalPlaces = max(0, min(2, $decimalPlaces));
        $negative = $minor < 0;
        $minor = abs($minor);

        if ($decimalPlaces === 0) {
            $whole = intdiv($minor + 50, 100);

            return ($negative ? '-' : '').$whole;
        }

        if ($decimalPlaces === 1) {
            $tenths = intdiv($minor + 5, 10);

            return ($negative ? '-' : '').intdiv($tenths, 10).'.'.($tenths % 10);
        }

        return ($negative ? '-' : '').Money::fromMinor($minor);
    }

    /**
     * @param  array<string, mixed>  $display
     */
    private function taxPresentation(?string $behavior, array $display): ?string
    {
        return match ($behavior) {
            'tax_inclusive' => ($display['tax_inclusive_display'] ?? false) ? 'tax_inclusive' : null,
            'tax_exclusive' => ($display['tax_exclusive_display'] ?? true) ? 'tax_exclusive' : null,
            'tax_hidden_until_checkout' => 'tax_hidden_until_checkout',
            'tax_downstream' => 'tax_downstream',
            default => null,
        };
    }
}
