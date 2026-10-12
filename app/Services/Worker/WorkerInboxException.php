<?php

declare(strict_types=1);

namespace App\Services\Worker;

use RuntimeException;

final class WorkerInboxException extends RuntimeException
{
    public static function conversationNotOpen(): self
    {
        return new self('Only open conversations can receive worker replies.');
    }
}
