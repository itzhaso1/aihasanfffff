<?php

namespace App\Exceptions\Api;

use Throwable;

class ComplianceUnavailableException extends ApiApplicationException
{
    public function __construct(string $message = 'Electronic-invoice compliance artifacts are not available for this document.', ?Throwable $previous = null)
    {
        parent::__construct($message, ApiErrorCode::ComplianceUnavailable, 409, $previous);
    }
}
