<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum WorkerEngagementDurationUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function addTo(CarbonInterface $startsAt, int $value): CarbonImmutable
    {
        $start = CarbonImmutable::instance($startsAt);

        return match ($this) {
            self::Day => $start->addDays($value),
            self::Week => $start->addWeeks($value),
            self::Month => $start->addMonthsNoOverflow($value),
        };
    }

    public function labelFor(int $value): string
    {
        return match ($this) {
            self::Day => $value === 1 ? 'يوم واحد' : $value.' أيام',
            self::Week => $value === 1 ? 'أسبوع واحد' : $value.' أسابيع',
            self::Month => $value === 1 ? 'شهر واحد' : $value.' أشهر',
        };
    }
}
