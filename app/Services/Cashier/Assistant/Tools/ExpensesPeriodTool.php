<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Finance\ReportService;
use App\Support\Money\Money;

final class ExpensesPeriodTool extends AbstractPeriodCashierTool
{
    public function __construct(
        CashierAssistantPeriodResolver $periodResolver,
        private readonly ReportService $reportService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'expenses.period';
    }

    public function description(): string
    {
        return 'Total finance expenses for a period.';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $summary = $this->reportService->summary($period['from'], $period['to']);
        $total = Money::of($summary['expenses']['total_expenses'] ?? 0);

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_expenses',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => $total,
                'expenses_count' => (int) ($summary['expenses']['expenses_count'] ?? 0),
            ],
        ));
    }
}
