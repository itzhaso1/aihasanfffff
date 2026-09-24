<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\ReportService;
use App\Support\Money\Money;

final class ExpensesByCategoryTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly ReportService $reportService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'expenses.by_category';
    }

    public function description(): string
    {
        return 'Expense-module totals grouped by category for a period (not posted P&L operating expenses).';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $summary = $this->reportService->summary($period['from'], $period['to']);
        $rows = $summary['expensesByCategory'] ?? collect();

        $items = [];
        foreach ($rows as $row) {
            $name = (string) data_get($row, 'category_name', data_get($row, 'name', 'غير مصنف'));
            $items[] = [
                'name' => $name !== '' ? $name : 'غير مصنف',
                'value' => Money::of(data_get($row, 'total', 0)),
            ];
        }

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_expenses',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'items' => $items,
                'metric_kind' => 'expense_module_by_category',
                'label_ar' => 'المصاريف حسب الفئة من نظام المصروفات',
                'label_en' => 'Expense-module totals by category',
            ],
        ));
    }
}
