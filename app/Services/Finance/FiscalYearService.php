<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceFiscalYear;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FiscalYearService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload): FinanceFiscalYear
    {
        $startDate = Carbon::parse((string) $payload['start_date'])->startOfDay();
        $endDate = Carbon::parse((string) $payload['end_date'])->endOfDay();
        if ($endDate->lt($startDate)) {
            throw new RuntimeException('تاريخ نهاية السنة المالية يجب أن يكون بعد تاريخ البداية.');
        }

        $this->assertNoOverlap($workspace->id, null, $startDate, $endDate);

        return FinanceFiscalYear::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => trim((string) $payload['name']),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'status' => $payload['status'] ?? 'open',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function update(FinanceFiscalYear $fiscalYear, array $payload): FinanceFiscalYear
    {
        if ($fiscalYear->status === 'closed') {
            throw new RuntimeException('لا يمكن تعديل سنة مالية مغلقة.');
        }

        $startDate = Carbon::parse((string) $payload['start_date'])->startOfDay();
        $endDate = Carbon::parse((string) $payload['end_date'])->endOfDay();
        if ($endDate->lt($startDate)) {
            throw new RuntimeException('تاريخ نهاية السنة المالية يجب أن يكون بعد تاريخ البداية.');
        }

        $this->assertNoOverlap((int) $fiscalYear->workspace_id, (int) $fiscalYear->id, $startDate, $endDate);

        $fiscalYear->update([
            'name' => trim((string) $payload['name']),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]);

        return $fiscalYear->refresh();
    }

    public function closeYear(FinanceFiscalYear $fiscalYear): FinanceFiscalYear
    {
        $openPeriods = $fiscalYear->periods()->where('status', 'open')->count();
        if ($openPeriods > 0) {
            throw new RuntimeException('لا يمكن إغلاق السنة المالية مع وجود فترات محاسبية مفتوحة.');
        }

        $fiscalYear->update(['status' => 'closed']);

        return $fiscalYear->refresh();
    }

    public function openYear(FinanceFiscalYear $fiscalYear): FinanceFiscalYear
    {
        $fiscalYear->update(['status' => 'open']);

        return $fiscalYear->refresh();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function addPeriod(FinanceFiscalYear $fiscalYear, array $payload): FinanceAccountingPeriod
    {
        $startDate = Carbon::parse((string) $payload['start_date'])->startOfDay();
        $endDate = Carbon::parse((string) $payload['end_date'])->endOfDay();
        if ($endDate->lt($startDate)) {
            throw new RuntimeException('تاريخ نهاية الفترة يجب أن يكون بعد تاريخ البداية.');
        }
        if ($startDate->lt($fiscalYear->start_date) || $endDate->gt($fiscalYear->end_date)) {
            throw new RuntimeException('الفترة المحاسبية يجب أن تكون ضمن نطاق السنة المالية.');
        }

        $overlap = FinanceAccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where(function ($query) use ($startDate, $endDate): void {
                $query
                    ->whereBetween('start_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->orWhereBetween('end_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->orWhere(function ($nested) use ($startDate, $endDate): void {
                        $nested->whereDate('start_date', '<=', $startDate->toDateString())
                            ->whereDate('end_date', '>=', $endDate->toDateString());
                    });
            })
            ->exists();
        if ($overlap) {
            throw new RuntimeException('لا يمكن إنشاء فترة متداخلة مع فترة محاسبية أخرى.');
        }

        return FinanceAccountingPeriod::withoutGlobalScopes()->create([
            'workspace_id' => $fiscalYear->workspace_id,
            'fiscal_year_id' => $fiscalYear->id,
            'name' => trim((string) $payload['name']),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'status' => $payload['status'] ?? 'open',
        ]);
    }

    public function setPeriodStatus(FinanceAccountingPeriod $period, string $status): FinanceAccountingPeriod
    {
        if (! in_array($status, ['open', 'closed'], true)) {
            throw new RuntimeException('حالة الفترة غير صحيحة.');
        }

        $period->update(['status' => $status]);

        return $period->refresh();
    }

    public function generateMonthlyPeriods(FinanceFiscalYear $fiscalYear): int
    {
        if ($fiscalYear->periods()->count() > 0) {
            throw new RuntimeException('لا يمكن التوليد التلقائي مع وجود فترات محاسبية مسبقة.');
        }

        return DB::transaction(function () use ($fiscalYear): int {
            $cursor = $fiscalYear->start_date->copy()->startOfMonth();
            $end = $fiscalYear->end_date->copy()->endOfDay();
            $created = 0;

            while ($cursor->lte($end)) {
                $periodStart = $cursor->copy()->max($fiscalYear->start_date);
                $periodEnd = $cursor->copy()->endOfMonth()->min($fiscalYear->end_date);
                FinanceAccountingPeriod::withoutGlobalScopes()->create([
                    'workspace_id' => $fiscalYear->workspace_id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'name' => $periodStart->format('Y-m'),
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'status' => 'open',
                ]);
                $created++;
                $cursor->addMonthNoOverflow()->startOfMonth();
            }

            return $created;
        });
    }

    private function assertNoOverlap(int $workspaceId, ?int $ignoreId, Carbon $startDate, Carbon $endDate): void
    {
        $overlap = FinanceFiscalYear::query()
            ->where('workspace_id', $workspaceId)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where(function ($query) use ($startDate, $endDate): void {
                $query
                    ->whereBetween('start_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->orWhereBetween('end_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->orWhere(function ($nested) use ($startDate, $endDate): void {
                        $nested->whereDate('start_date', '<=', $startDate->toDateString())
                            ->whereDate('end_date', '>=', $endDate->toDateString());
                    });
            })
            ->exists();

        if ($overlap) {
            throw new RuntimeException('نطاق السنة المالية يتداخل مع سنة مالية أخرى.');
        }
    }
}
