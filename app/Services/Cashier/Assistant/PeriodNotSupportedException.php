<?php

namespace App\Services\Cashier\Assistant;

use InvalidArgumentException;

final class PeriodNotSupportedException extends InvalidArgumentException
{
    /**
     * @param  array{from: string, to: string, label?: string}  $requestedPeriod
     */
    public function __construct(
        public readonly array $requestedPeriod,
    ) {
        parent::__construct('period_not_supported');
    }
}
