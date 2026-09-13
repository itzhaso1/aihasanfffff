<?php

namespace App\EInvoicing;

use App\Enums\EInvoicing\ComplianceStatus;
use InvalidArgumentException;

/**
 * Domain transitions for electronic-invoice compliance status.
 *
 * This is not a submission worker and does not talk to any gateway.
 * Business invoice status and payment status remain outside this machine.
 */
final class ComplianceStateMachine
{
    /**
     * @return list<ComplianceStatus>
     */
    public static function allowedTargets(ComplianceStatus $from): array
    {
        return match ($from) {
            ComplianceStatus::Ready => [
                ComplianceStatus::Generated,
                ComplianceStatus::Failed,
            ],
            ComplianceStatus::Generated => [
                ComplianceStatus::Queued,
                ComplianceStatus::Failed,
            ],
            ComplianceStatus::Queued => [
                ComplianceStatus::Submitted,
                ComplianceStatus::Failed,
            ],
            ComplianceStatus::Submitted => [
                ComplianceStatus::Cleared,
                ComplianceStatus::Reported,
                ComplianceStatus::Rejected,
                ComplianceStatus::Failed,
            ],
            ComplianceStatus::Rejected => [
                ComplianceStatus::Ready,
                ComplianceStatus::Failed,
            ],
            ComplianceStatus::Failed => [
                ComplianceStatus::Ready,
                ComplianceStatus::Queued,
            ],
            ComplianceStatus::NotApplicable,
            ComplianceStatus::Cleared,
            ComplianceStatus::Reported => [],
        };
    }

    public static function canTransition(ComplianceStatus $from, ComplianceStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        foreach (self::allowedTargets($from) as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    public static function assertCanTransition(ComplianceStatus $from, ComplianceStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid electronic-invoice compliance transition from [%s] to [%s].',
                $from->value,
                $to->value,
            ));
        }
    }
}
