<?php

namespace App\EInvoicing;

use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Support\Money\Money;

final readonly class EInvoiceLine
{
    public function __construct(
        public ?string $description,
        public ?string $productName,
        public string $quantity,
        public string $unitPrice,
        public string $discount,
        public ?string $taxableAmount,
        public ElectronicTaxClassification $taxClassification,
        public ?string $taxRate,
        public ?string $taxAmount,
        public string $total,
        public ?string $exemptionReason,
        public ?string $exemptionCode = null,
        public ?string $unitCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $line
     */
    public static function fromSnapshot(array $line): self
    {
        $taxAmount = $line['tax_amount'] ?? null;

        return new self(
            description: isset($line['description']) ? (string) $line['description'] : null,
            productName: isset($line['product_name']) ? (string) $line['product_name'] : null,
            quantity: (string) ($line['quantity'] ?? '0.000'),
            unitPrice: Money::of($line['unit_price'] ?? 0),
            discount: Money::of($line['discount'] ?? 0),
            taxableAmount: array_key_exists('taxable_amount', $line) && $line['taxable_amount'] !== null
                ? Money::of($line['taxable_amount'])
                : (array_key_exists('subtotal', $line) && $line['subtotal'] !== null ? Money::of($line['subtotal']) : null),
            taxClassification: ElectronicTaxClassification::fromSnapshotValue($line['tax_profile_type'] ?? null),
            taxRate: array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null ? Money::of($line['tax_rate']) : null,
            taxAmount: $taxAmount !== null ? Money::of($taxAmount) : null,
            total: Money::of($line['total'] ?? 0),
            exemptionReason: isset($line['exemption_reason']) ? (string) $line['exemption_reason'] : null,
            exemptionCode: isset($line['exemption_code']) ? (string) $line['exemption_code'] : null,
            unitCode: isset($line['unit_code']) && $line['unit_code'] !== '' ? (string) $line['unit_code'] : null,
        );
    }

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
            'tax_classification' => $this->taxClassification->value,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'exemption_reason' => $this->exemptionReason,
            'exemption_code' => $this->exemptionCode,
            'unit_code' => $this->unitCode,
        ];
    }
}
