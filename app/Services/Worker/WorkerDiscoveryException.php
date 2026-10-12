<?php

declare(strict_types=1);

namespace App\Services\Worker;

use RuntimeException;

final class WorkerDiscoveryException extends RuntimeException
{
    public static function workspaceAccessDenied(): self
    {
        return new self('You are not authorized to discover independent workers in this workspace.');
    }

    public static function workerNotDiscoverable(): self
    {
        return new self('This worker is not currently available through discovery.');
    }

    public static function openEngagementExists(): self
    {
        return new self('This workspace already has an open engagement with this worker.');
    }
}
