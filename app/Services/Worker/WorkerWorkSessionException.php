<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerWorkSessionSegmentType;
use App\Enums\WorkerWorkSessionStatus;
use RuntimeException;

final class WorkerWorkSessionException extends RuntimeException
{
    public static function engagementNotActive(): self
    {
        return new self('A work session requires an active worker engagement.');
    }

    public static function engagementCannotEnd(): self
    {
        return new self('This engagement cannot close a work session in its current state.');
    }

    public static function workspaceMismatch(): self
    {
        return new self('The work session does not belong to the current workspace.');
    }

    public static function workerNotEligible(): self
    {
        return new self('The worker is not eligible to start a work session.');
    }

    public static function activeSessionExists(): self
    {
        return new self('The worker engagement already has an active work session.');
    }

    public static function activeSessionKeyRequired(): self
    {
        return new self('An active work session requires an active-session key.');
    }

    public static function noActiveSession(): self
    {
        return new self('There is no active work session for this engagement.');
    }

    public static function endedSessionRequiresEndData(): self
    {
        return new self('An ended work session requires an end timestamp and duration.');
    }

    public static function invalidSessionTransition(
        WorkerWorkSessionStatus $current,
        WorkerWorkSessionStatus $next,
    ): self {
        return new self(sprintf(
            'A work session cannot transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }

    public static function invalidSegmentTransition(
        WorkerWorkSessionSegmentType $current,
        WorkerWorkSessionSegmentType $next,
    ): self {
        return new self(sprintf(
            'A work-session segment cannot transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }

    public static function segmentTypeRequired(): self
    {
        return new self('A work-session segment requires a valid segment type.');
    }

    public static function segmentNotOpen(): self
    {
        return new self('The work session has no open segment.');
    }

    public static function overlappingSegments(): self
    {
        return new self('The work session has overlapping open segments.');
    }

    public static function segmentEndInvalid(): self
    {
        return new self('A work-session segment cannot end before it starts.');
    }

    public static function segmentDeletionForbidden(): self
    {
        return new self('Work-session segments cannot be deleted.');
    }

    public static function historicalSessionImmutable(): self
    {
        return new self('An ended work session cannot be changed.');
    }

    public static function sessionDeletionForbidden(): self
    {
        return new self('Work sessions cannot be deleted.');
    }
}
