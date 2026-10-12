<?php

declare(strict_types=1);

namespace App\Services\Worker;

use RuntimeException;

final class WorkerEngagementCapabilityException extends RuntimeException
{
    public static function workspaceMismatch(): self
    {
        return new self('The engagement does not belong to the selected workspace.');
    }

    public static function engagementNotEligible(): self
    {
        return new self('Capabilities can only be managed for an eligible engagement.');
    }

    public static function invalidCapability(): self
    {
        return new self('The requested capability is not available.');
    }
}
