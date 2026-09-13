<?php

namespace App\Services\Finance\Tax;

use App\Enums\Finance\TaxPriceMode;
use App\Enums\Finance\TaxProfileType;

final readonly class TaxLineResult
{
    public function __construct(
        public float $quantity,
        public float $unitPrice,
        public float $grossAmount,
        public float $discountAmount,
        public float $taxableAmount,
        public TaxProfileType $classification,
        public float $taxRate,
        public float $taxAmount,
        public float $total,
        public TaxPriceMode $priceMode,
        public ?string $exemptionReason = null,
        public ?string $exemptionCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'gross_amount' => $this->grossAmount,
            'discount' => $this->discountAmount,
            'taxable_amount' => $this->taxableAmount,
            'tax_profile_type' => $this->classification->value,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'tax_price_mode' => $this->priceMode->value,
            'exemption_reason' => $this->exemptionReason,
            'exemption_code' => $this->exemptionCode,
        ];
    }
}
