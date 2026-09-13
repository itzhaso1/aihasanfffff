<?php

namespace App\Services\Finance;

use App\Models\Workspace;
use App\Services\Finance\Tax\TaxCalculationService;

/**
 * Backward-compatible facade over TaxCalculationService.
 * New invoice-domain code should inject TaxCalculationService.
 */
class TaxService
{
    public function __construct(
        private readonly TaxCalculationService $calculator,
    ) {}

    /**
     * @return array{type:string, rate:float}
     */
    public function defaultProfileForWorkspace(Workspace $workspace): array
    {
        return $this->calculator->defaultProfileForWorkspace($workspace);
    }

    /**
     * @return array{taxable_amount:float,tax_amount:float,total:float}
     */
    public function calculateAmount(float $amount, string $taxType, float $rate): array
    {
        return $this->calculator->calculateAmount($amount, $taxType, $rate);
    }

    /**
     * @return array{taxable_amount:float,tax_amount:float,total:float}
     */
    public function calculateLine(float $quantity, float $unitPrice, float $discount, string $taxType, float $rate): array
    {
        return $this->calculator->calculateLine($quantity, $unitPrice, $discount, $taxType, $rate);
    }

    public function isTaxable(string $taxType): bool
    {
        return $this->calculator->isTaxable($taxType);
    }

    public function roundMoney(float $amount): float
    {
        return $this->calculator->roundMoney($amount);
    }
}
