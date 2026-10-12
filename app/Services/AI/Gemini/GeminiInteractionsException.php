<?php

namespace App\Services\AI\Gemini;

use RuntimeException;

final class GeminiInteractionsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
