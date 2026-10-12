<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerActivityEventType;
use RuntimeException;

final class WorkerActivityEventException extends RuntimeException
{
    public static function historicalEventImmutable(): self
    {
        return new self('Worker activity events are append-only.');
    }

    public static function invalidSessionEvent(WorkerActivityEventType $type): self
    {
        return new self(sprintf(
            'The activity event [%s] is not valid for the current session state.',
            $type->value,
        ));
    }

    public static function inconsistentAttribution(): self
    {
        return new self('The activity event attribution does not match its engagement.');
    }
}
