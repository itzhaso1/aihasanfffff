<?php

declare(strict_types=1);

namespace App\Services\Booking\Exceptions;

class BookingUnavailableException extends BookingException
{
    /**
     * @param  list<array<string, mixed>>  $alternatives
     */
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'slot_unavailable',
        public readonly array $alternatives = [],
    ) {
        parent::__construct($message);
    }
}
