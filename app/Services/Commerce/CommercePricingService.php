<?php

namespace App\Services\Commerce;

use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Workspace;
use App\Services\Finance\Tax\TaxRateResolver;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Resolves authoritative Chat Catalog line prices for Commerce orders.
 *
 * There is intentionally no promotion engine in this phase. Catalog base,
 * sale, and compare-at values are the only price inputs.
 */
final class CommercePricingService
{
    public function __construct(
        private readonly TaxRateResolver $taxRateResolver,
    ) {}

    public function resolve(
        Workspace $workspace,
        ChatCatalog $catalog,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        int $quantity,
        ?string $expectedCurrency = null,
    ): CommercePriceResolution {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        if ($variant && (int) $variant->chat_catalog_item_id !== (int) $item->id) {
            throw new InvalidArgumentException('Catalog variant does not belong to the selected item.');
        }

        $currency = strtoupper((string) (
            $variant?->currency
            ?: $item->currency
            ?: $catalog->default_currency
        ));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Catalog item currency is invalid.');
        }
        if ($expectedCurrency !== null && strtoupper($expectedCurrency) !== $currency) {
            throw new InvalidArgumentException('All cart lines must use the cart currency.');
        }

        $basePrice = $variant?->price ?? $item->price;
        if ($basePrice === null || Money::cmp($basePrice, '0') < 0) {
            throw new InvalidArgumentException('Catalog item does not have a valid price.');
        }

        $salePrice = $this->salePriceFor($item, $variant);
        $unitPrice = Money::of($salePrice ?? $basePrice);
        $compareAtPrice = $variant?->compare_at_price ?? $item->compare_at_price;
        $compareAtPrice = $compareAtPrice !== null ? Money::of($compareAtPrice) : null;
        $subtotal = Money::quantityTimesUnitPrice($quantity, $unitPrice);
        $taxPresentation = $this->taxPresentation($item);
        $tax = $this->calculateTax($workspace, $subtotal, $taxPresentation);

        return new CommercePriceResolution(
            catalogItemId: (int) $item->id,
            catalogVariantId: $variant?->id ? (int) $variant->id : null,
            quantity: $quantity,
            unitPrice: $unitPrice,
            salePrice: $salePrice !== null ? Money::of($salePrice) : null,
            compareAtPrice: $compareAtPrice,
            currency: $currency,
            subtotal: $subtotal,
            discountAmount: Money::fromMinor(0),
            taxableAmount: $tax['taxable_amount'],
            taxAmount: $tax['tax_amount'],
            taxRate: $tax['tax_rate'],
            taxPresentation: $taxPresentation,
            totalBeforeTax: $subtotal,
            total: $tax['total'],
        );
    }

    private function salePriceFor(
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
    ): ?string {
        if ($variant?->sale_price !== null
            && Money::cmp($variant->sale_price, '0') >= 0) {
            return (string) $variant->sale_price;
        }

        return $item->effectiveSalePrice();
    }

    private function taxPresentation(ChatCatalogItem $item): string
    {
        return in_array($item->tax_display_behavior, [
            'tax_inclusive',
            'tax_exclusive',
            'tax_hidden_until_checkout',
            'tax_downstream',
        ], true)
            ? $item->tax_display_behavior
            : 'tax_downstream';
    }

    /**
     * @return array{taxable_amount:string,tax_amount:string,tax_rate:?string,total:string}
     */
    private function calculateTax(
        Workspace $workspace,
        string $subtotal,
        string $presentation,
    ): array {
        if ($presentation === 'tax_downstream') {
            return [
                'taxable_amount' => $subtotal,
                'tax_amount' => Money::fromMinor(0),
                'tax_rate' => null,
                'total' => $subtotal,
            ];
        }

        $profile = $this->taxRateResolver->defaultProfileFixed((int) $workspace->id);
        $rate = $profile['rate'];
        $isTaxable = $profile['type'] === 'standard' && Money::isPositive($rate);
        if (! $isTaxable) {
            return [
                'taxable_amount' => $subtotal,
                'tax_amount' => Money::fromMinor(0),
                'tax_rate' => 0.0,
                'total' => $subtotal,
            ];
        }

        if ($presentation === 'tax_inclusive') {
            $tax = Money::extractInclusiveTaxFixedRate($subtotal, $rate);

            return [
                'taxable_amount' => Money::sub($subtotal, $tax),
                'tax_amount' => $tax,
                'tax_rate' => $rate,
                'total' => $subtotal,
            ];
        }

        $tax = Money::percentOfFixedRate($subtotal, $rate);

        return [
            'taxable_amount' => $subtotal,
            'tax_amount' => $tax,
            'tax_rate' => $rate,
            'total' => Money::add($subtotal, $tax),
        ];
    }
}
