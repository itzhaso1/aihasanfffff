<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceTotalsDto
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
