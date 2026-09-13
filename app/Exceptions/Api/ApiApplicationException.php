<?php

namespace App\Exceptions\Api;

use RuntimeException;
use Throwable;

class ApiApplicationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ApiErrorCode $errorCode,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
