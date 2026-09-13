<?php

namespace App\Exceptions\Api;

use Throwable;

class IdempotencyConflictException extends ApiApplicationException
{
    public function __construct(string $message = 'The request conflicted with an existing invoice artifact.', ?Throwable $previous = null)
    {
        parent::__construct($message, ApiErrorCode::IdempotencyConflict, 409, $previous);
    }
}
