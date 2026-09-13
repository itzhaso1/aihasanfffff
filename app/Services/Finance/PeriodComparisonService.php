<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

class PeriodComparisonService
{
    public function __construct(
        private readonly FinanceAccountingKpiService $accountingKpiService,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function compare(int $workspaceId): array
    {
        $now = now();

        return [
            'today' => $this->window($workspaceId, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'this_week' => $this->window($workspaceId, $now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
            'this_month' => $this->window($workspaceId, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'last_month' => $this->window($workspaceId, $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()),
            'this_year' => $this->window($workspaceId, $now->copy()->startOfYear(), $now->copy()->endOfYear()),
            'previous_year' => $this->window($workspaceId, $now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function window(int $workspaceId, Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $sales = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->whereBetween('issue_date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(taxable_amount),0) as revenue')
            ->selectRaw('COALESCE(SUM(total),0) as total')
            ->selectRaw('COALESCE(SUM(amount_due),0) as due')
            ->selectRaw('COUNT(*) as invoices')
            ->first();

        $overdue = (int) FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->whereBetween('due_date', [$fromDate, $toDate])
            ->count();

        $cash = (float) FinanceTreasuryAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'cash')
            ->sum('current_balance');
        $bank = (float) FinanceTreasuryAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'bank')
            ->sum('current_balance');

        $accounting = $this->accountingKpiService->period($workspaceId, $fromDate, $toDate);

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'revenue' => $accounting['revenue'],
            'expenses' => $accounting['expenses'],
            'gross_profit' => $accounting['gross_profit'],
            'net_profit' => $accounting['net_profit'],
            'receivables' => $accounting['receivables'],
            'overdue_invoices' => $overdue,
            'invoice_count' => (int) ($sales->invoices ?? 0),
            'cash' => Money::of($cash),
            'bank' => Money::of($bank),
        ];
    }
}
