<?php

namespace App\EInvoicing;

use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Support\Money\Money;

final readonly class EInvoiceTax
{
    /**
     * @param  array<string, mixed>|null  $breakdown
     */
    public function __construct(
        public ElectronicTaxClassification $classification,
        public string $rate,
        public string $amount,
        public ?string $priceMode,
        public ?array $breakdown,
    ) {}

    /**
     * @param  array<string, mixed>  $tax
     */
    public static function fromSnapshot(array $tax): self
    {
        $rate = $tax['rate'] ?? $tax['configured_rate'] ?? 0;

        return new self(
            classification: ElectronicTaxClassification::fromSnapshotValue($tax['profile_type'] ?? null),
            rate: Money::of($rate ?? 0),
            amount: Money::of($tax['amount'] ?? 0),
            priceMode: isset($tax['price_mode']) ? (string) $tax['price_mode'] : null,
            breakdown: is_array($tax['breakdown'] ?? null) ? $tax['breakdown'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification->value,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'price_mode' => $this->priceMode,
            'breakdown' => $this->breakdown,
        ];
    }
}
