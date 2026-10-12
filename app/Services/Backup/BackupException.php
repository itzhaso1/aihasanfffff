<?php

namespace App\Services\Backup;

use RuntimeException;

class BackupException extends RuntimeException
{
    public static function failed(string $message): self
    {
        return new self($message);
    }
}
