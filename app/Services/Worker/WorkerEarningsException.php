<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEarningPeriodStatus;
use RuntimeException;

final class WorkerEarningsException extends RuntimeException
{
    public static function invalidPeriodBoundaries(): self
    {
        return new self('An earning period must end after it starts.');
    }

    public static function workspaceMismatch(): self
    {
        return new self('The earning record does not belong to the selected workspace.');
    }

    public static function engagementMismatch(): self
    {
        return new self('The earning record does not belong to the selected engagement.');
    }

    public static function engagementNotEligible(): self
    {
        return new self('This engagement cannot receive earning records in its current state.');
    }

    public static function periodAlreadyExists(): self
    {
        return new self('An earning period with these boundaries already exists for this engagement.');
    }

    public static function invalidPeriodStatus(string $status): self
    {
        return new self(sprintf('The earning period has an invalid stored status [%s].', $status));
    }

    public static function invalidPeriodTransition(
        WorkerEarningPeriodStatus $current,
        WorkerEarningPeriodStatus $next,
    ): self {
        return new self(sprintf(
            'An earning period cannot transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }

    public static function periodImmutable(): self
    {
        return new self('Finalized earning periods cannot be edited.');
    }

    public static function lineImmutable(): self
    {
        return new self('Earning lines in finalized periods cannot be edited.');
    }

    public static function periodRequiresLines(): self
    {
        return new self('An earning period must contain at least one earning line.');
    }

    public static function mixedCurrencies(): self
    {
        return new self('An earning period cannot combine multiple currencies.');
    }

    public static function invalidCurrency(): self
    {
        return new self('An earning line must have one explicit three-letter currency.');
    }

    public static function negativePeriodTotal(): self
    {
        return new self('An earning period total cannot be negative.');
    }

    public static function invalidLineAmount(): self
    {
        return new self('Earning line amounts must be integer minor units with a non-zero value.');
    }

    public static function deductionMustBeNegative(): self
    {
        return new self('Deduction amounts must be negative integer minor units.');
    }

    public static function earningLineMustBePositive(): self
    {
        return new self('Base, commission, and bonus amounts must be positive integer minor units.');
    }

    public static function adjustmentReasonRequired(): self
    {
        return new self('Adjustments require a reason.');
    }

    public static function invalidSource(): self
    {
        return new self('The earning source is invalid or does not belong to this engagement.');
    }

    public static function sourceIdNotAllowed(): self
    {
        return new self('This earning source type cannot have a source ID.');
    }

    public static function periodNotEligible(): self
    {
        return new self('The earning period is not eligible for payout.');
    }

    public static function disputeNotAllowed(): self
    {
        return new self('This earning period cannot be disputed in its current state.');
    }

    public static function payoutExists(): self
    {
        return new self('This earning period already has an open payout record.');
    }
}
