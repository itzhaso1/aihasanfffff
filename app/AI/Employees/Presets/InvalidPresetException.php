<?php

declare(strict_types=1);

namespace App\AI\Employees\Presets;

use RuntimeException;

final class InvalidPresetException extends RuntimeException
{
    public function __construct(public readonly string $preset, string $reason)
    {
        parent::__construct('Invalid employee preset "'.$preset.'": '.$reason.'.');
    }
}
