<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\FinanceAccountingKpiService;
use App\Services\Finance\PeriodComparisonService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Validator;

final class ComparePeriodsTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        private readonly PeriodComparisonService $periodComparisonService,
        private readonly FinanceAccountingKpiService $accountingKpiService,
    ) {}

    public function name(): string
    {
        return 'compare.periods';
    }

    public function description(): string
    {
        return 'Compare this month vs last month using posted ledger KPIs (expenses are P&L, not expense-module totals).';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function parameterSchema(): array
    {
        return [
            'preset' => 'optional: month|week (defaults to month)',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        return Validator::validate($arguments, [
            'preset' => ['nullable', 'string', 'max:32'],
        ]);
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $compare = $this->periodComparisonService->compare((int) $workspace->id);
        $preset = strtolower(trim((string) ($arguments['preset'] ?? 'month')));

        if ($preset === 'week') {
            $current = $compare['this_week'];
            $now = now();
            $previousFrom = $now->copy()->subWeek()->startOfWeek()->toDateString();
            $previousTo = $now->copy()->subWeek()->endOfWeek()->toDateString();
            $previousKpi = $this->accountingKpiService->period((int) $workspace->id, $previousFrom, $previousTo);
            $previous = [
                'from' => $previousFrom,
                'to' => $previousTo,
                'revenue' => $previousKpi['revenue'],
                'expenses' => $previousKpi['expenses'],
                'net_profit' => $previousKpi['net_profit'],
            ];
            $currentKey = 'this_week';
            $previousKey = 'last_week';
        } else {
            $current = $compare['this_month'];
            $previous = $compare['last_month'];
            $currentKey = 'this_month';
            $previousKey = 'last_month';
        }

        $deltaRevenue = Money::sub($current['revenue'], $previous['revenue']);
        $deltaExpenses = Money::sub($current['expenses'], $previous['expenses']);
        $deltaNet = Money::sub($current['net_profit'], $previous['net_profit']);

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'period_comparison',
            $current['from'],
            $current['to'],
            [
                'available' => true,
                'current' => [
                    'label' => $currentKey,
                    'revenue' => $current['revenue'],
                    'expenses' => $current['expenses'],
                    'expenses_metric_kind' => 'ledger_operating_expenses',
                    'expenses_label_ar' => 'مصروفات التشغيل المرحّلة في الأرباح والخسائر',
                    'expenses_label_en' => 'Posted ledger operating expenses (P&L)',
                    'net_profit' => $current['net_profit'],
                    'from' => $current['from'],
                    'to' => $current['to'],
                ],
                'previous' => [
                    'label' => $previousKey,
                    'revenue' => $previous['revenue'],
                    'expenses' => $previous['expenses'],
                    'expenses_metric_kind' => 'ledger_operating_expenses',
                    'expenses_label_ar' => 'مصروفات التشغيل المرحّلة في الأرباح والخسائر',
                    'expenses_label_en' => 'Posted ledger operating expenses (P&L)',
                    'net_profit' => $previous['net_profit'],
                    'from' => $previous['from'],
                    'to' => $previous['to'],
                ],
                'delta' => [
                    'revenue' => $deltaRevenue,
                    'expenses' => $deltaExpenses,
                    'net_profit' => $deltaNet,
                ],
            ],
        ));
    }
}
