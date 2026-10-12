<?php

namespace App\Exceptions;

use RuntimeException;

class MissingWorkspaceContextException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self("Workspace context is required to query [{$model}].");
    }

    public static function forWrite(string $model, string $operation): self
    {
        return new self("Workspace context is required to {$operation} [{$model}].");
    }
}
