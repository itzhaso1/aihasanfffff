<?php

namespace App\Services\AI\Inbox\Tools;

use InvalidArgumentException;

final class InboxAgentToolRegistryException extends InvalidArgumentException
{
    public static function duplicate(string $name): self
    {
        return new self("Inbox agent tool [{$name}] is already registered.");
    }

    public static function unknown(string $name): self
    {
        return new self("Unknown Inbox agent tool [{$name}].");
    }
}
