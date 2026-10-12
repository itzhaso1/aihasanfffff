<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEngagementStatus;
use RuntimeException;

final class WorkerEngagementException extends RuntimeException
{
    public static function invalidTransition(
        WorkerEngagementStatus $current,
        WorkerEngagementStatus $next,
    ): self {
        return new self(sprintf(
            'A worker engagement cannot transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }

    public static function invalidStoredStatus(string $status): self
    {
        return new self(sprintf('The worker engagement has an invalid stored status [%s].', $status));
    }

    public static function workerNotEligible(): self
    {
        return new self('The worker is not eligible for a new engagement offer.');
    }

    public static function offerExpired(): self
    {
        return new self('انتهت صلاحية هذا العرض ولا يمكن قبوله.');
    }

    public static function contractRateUnavailable(): self
    {
        return new self('لا يوجد سعر منصة صالح لهذه المدة.');
    }

    public static function activeEngagementExists(): self
    {
        return new self('The worker already has an active engagement with this workspace.');
    }

    public static function workspaceMismatch(): self
    {
        return new self('The engagement does not belong to the selected workspace.');
    }

    public static function invalidProductAssignment(): self
    {
        return new self('One or more selected products are not available in this workspace.');
    }

    public static function invalidProductPriceRange(): self
    {
        return new self('The minimum product price cannot be greater than the maximum product price.');
    }

    public static function invalidProductPrice(): self
    {
        return new self('One or more engagement product prices are invalid.');
    }

    public static function guideNotEditable(): self
    {
        return new self('The work guide cannot be edited in the current engagement state.');
    }
}
