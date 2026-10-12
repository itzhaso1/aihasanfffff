<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerEarningSourceType: string
{
    case EngagementTerms = 'engagement_terms';
    case WorkSession = 'work_session';
    case ActivityEvent = 'activity_event';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::EngagementTerms => 'شروط الارتباط',
            self::WorkSession => 'جلسة عمل',
            self::ActivityEvent => 'حدث نشاط',
            self::Adjustment => 'تسوية',
        };
    }
}
