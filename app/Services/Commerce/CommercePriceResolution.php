<?php

namespace App\Services\Commerce;

/**
 * Immutable, server-authoritative price result for one catalog line.
 *
 * Monetary properties are canonical fixed-scale strings, never client input.
 */
final readonly class CommercePriceResolution
{
    public function __construct(
        public int $catalogItemId,
        public ?int $catalogVariantId,
        public int $quantity,
        public string $unitPrice,
        public ?string $salePrice,
        public ?string $compareAtPrice,
        public string $currency,
        public string $subtotal,
        public string $discountAmount,
        public string $taxableAmount,
        public string $taxAmount,
        public ?string $taxRate,
        public string $taxPresentation,
        public string $totalBeforeTax,
        public string $total,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function toSnapshot(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'sale_price' => $this->salePrice,
            'compare_at_price' => $this->compareAtPrice,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discountAmount,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxAmount,
            'tax_rate' => $this->taxRate,
            'tax_presentation' => $this->taxPresentation,
            'total_before_tax' => $this->totalBeforeTax,
            'total' => $this->total,
        ];
    }
}
