<?php

namespace App\Services\Finance\Api\Dto;

/**
 * @param  list<array<string, mixed>>|null  $breakdown
 */
final readonly class InvoiceTaxSummaryDto
{
    /**
     * @param  list<array<string, mixed>>|null  $breakdown
     */
    public function __construct(
        public string $rate,
        public string $amount,
        public ?string $classification,
        public ?string $priceMode,
        public ?array $breakdown,
        public ?string $engine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'amount' => $this->amount,
            'classification' => $this->classification,
            'price_mode' => $this->priceMode,
            'engine' => $this->engine,
            'breakdown' => $this->breakdown,
        ];
    }
}
