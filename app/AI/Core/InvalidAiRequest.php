<?php

declare(strict_types=1);

namespace App\AI\Core;

use InvalidArgumentException;

final class InvalidAiRequest extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self('Invalid AI request: '.$reason.'.');
    }
}
