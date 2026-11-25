<?php

namespace App\Services\Conversation\Exceptions;

use RuntimeException;

class HandoffConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $currentHandoffVersion = null,
        public readonly ?int $assignedUserId = null,
        public readonly ?string $assignedUserName = null,
    ) {
        parent::__construct($message);
    }
}
