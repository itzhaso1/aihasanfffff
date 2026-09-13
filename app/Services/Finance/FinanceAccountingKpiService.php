<?php

namespace App\Services\Finance;

use App\Support\Money\Money;

/**
 * Canonical accounting KPIs for dashboards and period windows.
 * Source of truth: posted/reversed journal lines on entry_date.
 */
class FinanceAccountingKpiService
{
    public const LIFETIME_FROM = '1970-01-01';

    public const RECEIVABLE_ACCOUNT = '1200';

    public const PAYABLE_ACCOUNT = '2000';

    public function __construct(
        private readonly LedgerReportService $ledgerReportService,
    ) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     revenue: string,
     *     cogs: string,
     *     gross_profit: string,
     *     expenses: string,
     *     operating_expenses: string,
     *     other_expenses: string,
     *     net_profit: string,
     *     receivables: string,
     *     payables: string,
     *     opening_cash: string,
     *     net_cash_change: string,
     *     closing_cash: string,
     *     profit_and_loss: array<string, mixed>,
     *     cash_flow: array<string, mixed>
     * }
     */
    public function period(int $workspaceId, string $from, string $to): array
    {
        $pnl = $this->ledgerReportService->profitAndLoss($workspaceId, $from, $to);
        $cash = $this->ledgerReportService->cashFlow($workspaceId, $from, $to);
        $expenses = Money::add($pnl['operating_expenses'], $pnl['other_expenses']);

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => Money::of($pnl['revenue']),
            'cogs' => Money::of($pnl['cogs']),
            'gross_profit' => Money::of($pnl['gross_profit']),
            'expenses' => $expenses,
            'operating_expenses' => Money::of($pnl['operating_expenses']),
            'other_expenses' => Money::of($pnl['other_expenses']),
            'net_profit' => Money::of($pnl['net_profit']),
            'receivables' => $this->ledgerReportService->accountNaturalBalance($workspaceId, self::RECEIVABLE_ACCOUNT, $to),
            'payables' => $this->ledgerReportService->accountNaturalBalance($workspaceId, self::PAYABLE_ACCOUNT, $to),
            'opening_cash' => Money::of($cash['opening_cash']),
            'net_cash_change' => Money::of($cash['net_change']),
            'closing_cash' => Money::of($cash['closing_cash']),
            'profit_and_loss' => $pnl,
            'cash_flow' => $cash,
        ];
    }
}
