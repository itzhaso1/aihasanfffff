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
