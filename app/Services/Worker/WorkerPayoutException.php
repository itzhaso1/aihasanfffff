<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerPayoutStatus;
use RuntimeException;

final class WorkerPayoutException extends RuntimeException
{
    public static function invalidTransition(
        WorkerPayoutStatus $current,
        WorkerPayoutStatus $next,
    ): self {
        return new self(sprintf(
            'A payout cannot transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }

    public static function invalidStoredStatus(string $status): self
    {
        return new self(sprintf('The payout has an invalid stored status [%s].', $status));
    }

    public static function historicalPayoutImmutable(): self
    {
        return new self('Paid or cancelled payout records cannot be edited.');
    }

    public static function payoutNotEligible(): self
    {
        return new self('The earning period is not eligible for payout.');
    }

    public static function openPayoutExists(): self
    {
        return new self('This earning period already has a pending or processing payout.');
    }

    public static function periodNotApproved(): self
    {
        return new self('The earning period must be approved before it can be marked paid.');
    }

    public static function invalidAmount(): self
    {
        return new self('A payout amount must be a positive integer minor-unit amount.');
    }

    public static function invalidCurrency(): self
    {
        return new self('A payout must have one explicit currency.');
    }

    public static function periodMismatch(): self
    {
        return new self('The payout does not belong to the selected earning period.');
    }

    public static function providerReferenceRequired(): self
    {
        return new self('A provider-neutral reference is required before marking a payout paid.');
    }

    public static function failureReasonRequired(): self
    {
        return new self('A reason is required for a failed or cancelled payout.');
    }
}
