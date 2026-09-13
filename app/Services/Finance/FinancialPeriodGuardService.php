<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceFiscalYear;
use Illuminate\Support\Carbon;
use RuntimeException;

class FinancialPeriodGuardService
{
    public function assertDateIsOpen(int $workspaceId, string $date, string $context): void
    {
        $normalizedDate = Carbon::parse($date)->toDateString();

        $closedPeriodExists = FinanceAccountingPeriod::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'closed')
            ->whereDate('start_date', '<=', $normalizedDate)
            ->whereDate('end_date', '>=', $normalizedDate)
            ->exists();

        if ($closedPeriodExists) {
            throw new RuntimeException("لا يمكن تنفيذ {$context} داخل فترة محاسبية مغلقة.");
        }

        $closedYearExists = FinanceFiscalYear::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'closed')
            ->whereDate('start_date', '<=', $normalizedDate)
            ->whereDate('end_date', '>=', $normalizedDate)
            ->exists();

        if ($closedYearExists) {
            throw new RuntimeException("لا يمكن تنفيذ {$context} داخل سنة مالية مغلقة.");
        }
    }
}
