<?php

namespace App\Exceptions\Api;

use Throwable;

class InvoiceCannotBeIssuedException extends ApiApplicationException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, ApiErrorCode::CannotIssue, 422, $previous);
    }
}
