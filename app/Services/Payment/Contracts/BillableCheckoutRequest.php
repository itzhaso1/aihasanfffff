<?php

namespace App\Services\Payment\Contracts;

final class BillableCheckoutRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $billableType,
        public readonly int $billableId,
        public readonly int $workspaceId,
        public readonly string $reference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly array $metadata = [],
    ) {}
}
