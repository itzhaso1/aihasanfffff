<?php

namespace App\Services\Cashier\Assistant;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class CashierAssistantPeriodResolver
{
    public function __construct(
        private readonly CashierAssistantQuestionSemantics $semantics,
    ) {}

    /**
     * @return array{from: string, to: string, label: string}
     */
    public function resolve(?string $preset = null, ?string $from = null, ?string $to = null): array
    {
        $now = now();

        if ($from !== null || $to !== null) {
            $start = Carbon::parse($from ?? $to)->toDateString();
            $end = Carbon::parse($to ?? $from)->toDateString();
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $period = [
                'from' => $start,
                'to' => $end,
                'label' => 'custom',
            ];
            $this->assertSupported($period);

            return $period;
        }

        if ($preset === null || trim((string) $preset) === '') {
            $preset = 'month';
        }

        $preset = strtolower(trim((string) $preset));

        $period = match ($preset) {
            'today', 'day' => [
                'from' => $now->toDateString(),
                'to' => $now->toDateString(),
                'label' => 'today',
            ],
            'week', 'this_week' => [
                'from' => $now->copy()->startOfWeek()->toDateString(),
                'to' => $now->copy()->endOfWeek()->toDateString(),
                'label' => 'this_week',
            ],
            'month', 'this_month' => [
                'from' => $now->copy()->startOfMonth()->toDateString(),
                'to' => $now->copy()->endOfMonth()->toDateString(),
                'label' => 'this_month',
            ],
            'last_month' => [
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'label' => 'last_month',
            ],
            default => throw new InvalidArgumentException('Unsupported period preset.'),
        };

        $this->assertSupported($period);

        return $period;
    }

    /**
     * @param  array{from: string, to: string, label?: string}  $period
     */
    public function assertSupported(array $period): void
    {
        if (! $this->semantics->isSupportedPeriodRange($period['from'], $period['to'])) {
            throw new PeriodNotSupportedException($period);
        }
    }
}
