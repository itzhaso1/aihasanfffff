<?php

namespace App\Services\Finance\Tax;

use App\Enums\Finance\TaxPriceMode;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Support\Money\Money;

final readonly class TaxCalculationResult
{
    /**
     * @param  array<int, TaxLineResult>  $lines
     * @param  array<int, TaxCategoryTotal>  $categoryTotals
     */
    public function __construct(
        public TaxPriceMode $priceMode,
        public float $subtotal,
        public float $discountTotal,
        public float $taxableAmount,
        public float $taxTotal,
        public float $grandTotal,
        public array $lines,
        public array $categoryTotals,
    ) {}

    /**
     * @param  array{type:string, rate:float}  $defaultProfile
     * @return array{type:string, rate:float}
     */
    public function headerProfile(array $defaultProfile): array
    {
        $types = array_values(array_unique(array_map(
            fn (TaxLineResult $line): string => $line->classification->value,
            $this->lines
        )));

        if (count($types) !== 1) {
            return $defaultProfile;
        }

        $rates = array_values(array_unique(array_map(
            fn (TaxLineResult $line): string => number_format($line->taxRate, 2, '.', ''),
            $this->lines
        )));

        return [
            'type' => $types[0],
            'rate' => count($rates) === 1 ? (float) $rates[0] : $defaultProfile['rate'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categoryTotalsToArray(): array
    {
        return array_map(
            fn (TaxCategoryTotal $total): array => $total->toArray(),
            $this->categoryTotals
        );
    }

    /**
     * @return array{subtotal:float,discount:float,taxable_amount:float,tax_amount:float,total:float}
     */
    public function totalsArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discountTotal,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxTotal,
            'total' => $this->grandTotal,
        ];
    }

    public function matchesPersistedInvoice(FinanceInvoice $invoice): bool
    {
        return $this->matchesHeaderAmounts(
            (float) $invoice->subtotal,
            (float) $invoice->discount,
            (float) $invoice->taxable_amount,
            (float) $invoice->tax_amount,
            (float) $invoice->total,
        ) && $this->matchesLineAmounts($invoice->items);
    }

    public function matchesPersistedCreditNote(FinanceCreditNote $note): bool
    {
        return $this->matchesHeaderAmounts(
            (float) $note->subtotal,
            (float) $note->discount,
            (float) $note->taxable_amount,
            (float) $note->tax_amount,
            (float) $note->total,
        ) && $this->matchesLineAmounts($note->items);
    }

    public function matchesHeaderAmounts(
        float $subtotal,
        float $discount,
        float $taxableAmount,
        float $taxAmount,
        float $total,
    ): bool {
        return Money::cmp($this->subtotal, $subtotal) === 0
            && Money::cmp($this->discountTotal, $discount) === 0
            && Money::cmp($this->taxableAmount, $taxableAmount) === 0
            && Money::cmp($this->taxTotal, $taxAmount) === 0
            && Money::cmp($this->grandTotal, $total) === 0;
    }

    /**
     * @param  iterable<int, object>  $lines
     */
    public function matchesLineAmounts(iterable $lines): bool
    {
        $stored = [];
        foreach ($lines as $line) {
            $stored[] = $line;
        }

        if (count($stored) !== count($this->lines)) {
            return false;
        }

        foreach ($this->lines as $index => $line) {
            $persisted = $stored[$index];
            if (Money::cmp($line->taxableAmount, $persisted->taxable_amount) !== 0
                || Money::cmp($line->taxAmount, $persisted->tax_amount) !== 0
                || Money::cmp($line->total, $persisted->total) !== 0) {
                return false;
            }
        }

        return true;
    }
}
