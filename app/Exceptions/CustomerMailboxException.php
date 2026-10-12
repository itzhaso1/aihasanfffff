<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CustomerMailboxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly ?int $workspaceId = null,
        public readonly ?int $emailAccountId = null,
        public readonly ?int $emailMessageId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
