<?php

namespace App\Exceptions\Api;

use Throwable;

class InvoiceAlreadyIssuedException extends ApiApplicationException
{
    public function __construct(string $message = 'Invoice is already issued.', ?Throwable $previous = null)
    {
        parent::__construct($message, ApiErrorCode::AlreadyIssued, 409, $previous);
    }
}
