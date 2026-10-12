<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerDisputeStatus: string
{
    case Open = 'open';

    case UnderReview = 'under_review';

    case Resolved = 'resolved';

    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوح',
            self::UnderReview => 'قيد المراجعة',
            self::Resolved => 'محلول',
            self::Closed => 'مغلق',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Open => [self::UnderReview],
            self::UnderReview => [self::Resolved],
            self::Resolved => [self::Closed],
            self::Closed => [],
        }, true);
    }
}
