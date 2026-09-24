<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\ReportService;
use App\Support\Money\Money;

final class ExpensesPeriodTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
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
        return 'Expense-module totals by expense_date (not posted P&L operating expenses).';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $summary = $this->reportService->summary($period['from'], $period['to']);
        $expenseSummary = $summary['expenseSummary'] ?? null;

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_expenses',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => Money::of($expenseSummary->total_expenses ?? 0),
                'expenses_count' => (int) ($expenseSummary->expenses_count ?? 0),
                'metric_kind' => 'expense_module_total',
                'label_ar' => 'مجموع المصروفات المسجلة في نظام المصروفات',
                'label_en' => 'Expense-module expenses',
            ],
        ));
    }
}
