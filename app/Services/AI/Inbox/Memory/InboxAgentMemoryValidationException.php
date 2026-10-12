<?php

namespace App\Services\AI\Inbox\Memory;

use InvalidArgumentException;

final class InboxAgentMemoryValidationException extends InvalidArgumentException
{
    public static function forField(string $field, string $reason): self
    {
        return new self('Invalid memory '.$field.': '.$reason);
    }
}
