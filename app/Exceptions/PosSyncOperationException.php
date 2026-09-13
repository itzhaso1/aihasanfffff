<?php

namespace App\Exceptions;

use RuntimeException;

class PosSyncOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function permanent(string $message, int $status = 422): self
    {
        return new self($message, false, $status);
    }

    public static function retryable(string $message, int $status = 503): self
    {
        return new self($message, true, $status);
    }
}
