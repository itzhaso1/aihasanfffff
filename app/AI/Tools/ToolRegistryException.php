<?php

declare(strict_types=1);

namespace App\AI\Tools;

use InvalidArgumentException;

final class ToolRegistryException extends InvalidArgumentException
{
    public static function duplicate(string $name): self
    {
        return new self('Duplicate tool "'.$name.'".');
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }

    public static function unknown(string $name): self
    {
        return new self('Unknown tool "'.$name.'".');
    }
}
