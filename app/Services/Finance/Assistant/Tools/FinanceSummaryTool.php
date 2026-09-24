<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\FinanceAccountingKpiService;
use App\Support\Authorization\FinanceAccess;

final class FinanceSummaryTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly FinanceAccountingKpiService $accountingKpiService,
        private readonly PayrollPeriodTool $payrollPeriodTool,
        private readonly FinanceAccess $financeAccess,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'finance.summary';
    }

    public function description(): string
    {
        return 'Financial KPI summary for a period from ledger KPIs and period-scoped paid payroll.';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $kpi = $this->accountingKpiService->period((int) $workspace->id, $period['from'], $period['to']);

        $payload = [
            'available' => true,
            'revenue' => $kpi['revenue'],
            'expenses' => $kpi['expenses'],
            'expenses_metric_kind' => 'ledger_operating_expenses',
            'expenses_label_ar' => 'مصروفات التشغيل المرحّلة في الأرباح والخسائر',
            'expenses_label_en' => 'Posted ledger operating expenses (P&L)',
            'gross_profit' => $kpi['gross_profit'],
            'net_profit' => $kpi['net_profit'],
            'cogs' => $kpi['cogs'],
            'payables' => $kpi['payables'],
            'receivables' => $kpi['receivables'],
        ];

        if ($this->financeAccess->can($user, $workspace, 'payroll.view')) {
            $payrollResult = $this->payrollPeriodTool->execute($workspace, $user, $arguments);
            $payload['payroll_paid_total'] = $payrollResult->available
                ? (string) ($payrollResult->data['value'] ?? '0.00')
                : '0.00';
            $payload['payroll_metric_kind'] = 'paid_payroll_period';
            $payload['payroll_label_ar'] = 'الرواتب المدفوعة خلال الفترة';
            $payload['payroll_label_en'] = 'Paid payroll for the period';
        }

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_summary',
            $period['from'],
            $period['to'],
            $payload,
        ));
    }
}
