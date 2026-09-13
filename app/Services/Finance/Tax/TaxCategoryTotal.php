<?php

namespace App\Services\Finance\Tax;

use App\Enums\Finance\TaxProfileType;

final readonly class TaxCategoryTotal
{
    public function __construct(
        public TaxProfileType $classification,
        public float $taxRate,
        public float $taxableAmount,
        public float $taxAmount,
        public int $lineCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tax_profile_type' => $this->classification->value,
            'tax_rate' => $this->taxRate,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxAmount,
            'line_count' => $this->lineCount,
        ];
    }
}
