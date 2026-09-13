<?php

namespace App\Exceptions\Api;

use Throwable;

class ProductionCryptoUnavailableException extends ApiApplicationException
{
    public function __construct(
        string $message = 'Production ZATCA cryptographic profile is unresolved and unavailable.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, ApiErrorCode::ProductionCryptoUnavailable, 409, $previous);
    }
}
