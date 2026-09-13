<?php

namespace App\EInvoicing;

use App\Support\Money\Money;

final readonly class EInvoiceTotals
{
    public function __construct(
        public string $subtotal,
        public string $discount,
        public string $taxableAmount,
        public string $taxAmount,
        public string $total,
        public ?string $amountPaid,
        public ?string $amountDue,
    ) {}

    /**
     * @param  array<string, mixed>  $totals
     */
    public static function fromSnapshot(array $totals): self
    {
        return new self(
            subtotal: Money::of($totals['subtotal'] ?? 0),
            discount: Money::of($totals['discount'] ?? 0),
            taxableAmount: Money::of($totals['taxable_amount'] ?? 0),
            taxAmount: Money::of($totals['tax_amount'] ?? 0),
            total: Money::of($totals['total'] ?? 0),
            amountPaid: array_key_exists('amount_paid', $totals) && $totals['amount_paid'] !== null
                ? Money::of($totals['amount_paid'])
                : null,
            amountDue: array_key_exists('amount_due', $totals) && $totals['amount_due'] !== null
                ? Money::of($totals['amount_due'])
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'amount_paid' => $this->amountPaid,
            'amount_due' => $this->amountDue,
        ];
    }
}
