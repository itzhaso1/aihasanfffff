<?php

namespace App\Services\AI\Inbox\Persona;

use InvalidArgumentException;

final class InboxAgentPersonaValidationException extends InvalidArgumentException
{
    public static function forField(string $field, string $reason): self
    {
        return new self('Invalid agent persona '.$field.': '.$reason);
    }
}
