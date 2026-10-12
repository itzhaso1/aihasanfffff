<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerDisputeStatus;
use RuntimeException;

final class WorkerDisputeException extends RuntimeException
{
    public static function invalidStoredStatus(string $status): self
    {
        return new self(sprintf('The worker dispute has an invalid stored status [%s].', $status));
    }

    public static function invalidTransition(
        WorkerDisputeStatus $current,
        WorkerDisputeStatus $next,
    ): self {
        return new self(sprintf(
            'A worker dispute cannot transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }

    public static function immutable(): self
    {
        return new self('Historical dispute details cannot be changed.');
    }

    public static function deletionForbidden(): self
    {
        return new self('Worker disputes cannot be deleted.');
    }

    public static function descriptionRequired(): self
    {
        return new self('A dispute description is required.');
    }

    public static function categoryRequired(): self
    {
        return new self('A dispute category is required.');
    }

    public static function resolutionSummaryRequired(): self
    {
        return new self('A resolution summary is required before resolving a dispute.');
    }

    public static function workspaceMismatch(): self
    {
        return new self('The dispute does not belong to the selected workspace.');
    }
}
