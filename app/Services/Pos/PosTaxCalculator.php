<?php

namespace App\Services\Pos;

use App\Models\Workspace;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * POS adapter over the finance TaxCalculationService.
 * Never trusts client-supplied tax or totals.
 */
class PosTaxCalculator
{
    public function __construct(
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    public function workspaceRate(?Workspace $workspace): float
    {
        if (! $workspace) {
            return 0.0;
        }

        $posRate = (float) data_get($workspace->settings ?? [], 'pos.tax_rate', 0);

        return $posRate > 0 ? Money::round($posRate) : 0.0;
    }

    public function taxAmount(?Workspace $workspace, float|string $subtotal, float|string $discountAmount): float
    {
        $rate = $this->workspaceRate($workspace);
        $taxable = $this->taxableAmount($subtotal, $discountAmount);
        $result = $this->taxCalculationService->calculateAmount((float) $taxable, 'standard', $rate);

        return Money::round($result['tax_amount']);
    }

    public function taxableAmount(float|string $subtotal, float|string $discountAmount): float
    {
        return Money::cmp($subtotal, $discountAmount) > 0
            ? Money::round(Money::sub($subtotal, $discountAmount))
            : 0.0;
    }

    /**
     * Allocate header tax across lines. Client line tax is ignored.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    public function attachLineTax(Collection $items, float $headerTax, float $rate): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $count = $items->count();
        $taxableShares = $items->map(fn (array $item): float => Money::round($item['total_amount'] ?? 0))->values();
        $taxableTotalMinor = 0;
        foreach ($taxableShares as $share) {
            $taxableTotalMinor += Money::minor($share);
        }
        $remainingMinor = Money::minor($headerTax);

        return $items->values()->map(function (array $item, int $index) use ($count, $taxableShares, $taxableTotalMinor, $headerTax, $rate, &$remainingMinor): array {
            $taxable = $taxableShares[$index];
            if ($index === $count - 1) {
                $taxMinor = max(0, $remainingMinor);
            } elseif ($taxableTotalMinor <= 0 || Money::minor($headerTax) <= 0) {
                $taxMinor = 0;
            } else {
                $taxMinor = (int) round(
                    Money::minor($headerTax) * Money::minor($taxable) / $taxableTotalMinor,
                    0,
                    PHP_ROUND_HALF_UP
                );
                $taxMinor = min($taxMinor, $remainingMinor);
                $remainingMinor -= $taxMinor;
            }

            $tax = Money::round(Money::fromMinor($taxMinor));
            $item['taxable_amount'] = $taxable;
            $item['tax_amount'] = $tax;
            $item['tax_rate'] = $this->effectiveRate($taxable, $tax, $rate);

            return $item;
        });
    }

    public function effectiveRate(float|string $taxable, float|string $tax, float $fallbackRate): float
    {
        $fallback = $fallbackRate > 0 ? Money::round($fallbackRate) : 0.0;
        if (Money::minor($taxable) <= 0) {
            return $fallback;
        }

        if ($fallback > 0 && Money::cmp($this->taxCalculationService->calculateAmount((float) $taxable, 'standard', $fallback)['tax_amount'], $tax) === 0) {
            return $fallback;
        }

        $rateMinor = (int) round(
            (Money::minor($tax) * 10000) / Money::minor($taxable),
            0,
            PHP_ROUND_HALF_UP
        );

        return Money::round(Money::fromMinor($rateMinor));
    }
}
