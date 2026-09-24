<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Support\Money\Money;

class PayrollPeriodTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'payroll.period';
    }

    public function description(): string
    {
        return 'Paid payroll total for a period (payment_status=paid, paid_at in range, sum net_amount).';
    }

    public function permission(): string
    {
        return 'payroll.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);

        $total = FinanceEmployeePayrollRecord::query()
            ->where('workspace_id', $workspace->id)
            ->where('payment_status', 'paid')
            ->whereDate('paid_at', '>=', $period['from'])
            ->whereDate('paid_at', '<=', $period['to'])
            ->sum('net_amount');

        $count = (int) FinanceEmployeePayrollRecord::query()
            ->where('workspace_id', $workspace->id)
            ->where('payment_status', 'paid')
            ->whereDate('paid_at', '>=', $period['from'])
            ->whereDate('paid_at', '<=', $period['to'])
            ->count();

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_payroll_records',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => Money::of($total),
                'records_count' => $count,
                'metric_kind' => 'paid_payroll_period',
                'label_ar' => 'الرواتب المدفوعة خلال الفترة',
                'label_en' => 'Paid payroll for the period',
            ],
        ));
    }
}
