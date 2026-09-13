<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceLineDto
{
    public function __construct(
        public ?string $description,
        public ?string $productName,
        public string $quantity,
        public string $unitPrice,
        public string $discount,
        public ?string $taxableAmount,
        public ?string $taxRate,
        public ?string $taxAmount,
        public string $total,
        public ?string $taxClassification,
        public ?string $unitCode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'discount' => $this->discount,
            'taxable_amount' => $this->taxableAmount,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'tax_classification' => $this->taxClassification,
            'unit_code' => $this->unitCode,
        ];
    }
}
