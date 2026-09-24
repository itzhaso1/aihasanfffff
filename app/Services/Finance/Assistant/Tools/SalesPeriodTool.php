<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\FinanceAccountingKpiService;

final class SalesPeriodTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly FinanceAccountingKpiService $accountingKpiService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'sales.period';
    }

    public function description(): string
    {
        return 'Ledger revenue for a period.';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $kpi = $this->accountingKpiService->period((int) $workspace->id, $period['from'], $period['to']);

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'ledger_revenue',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => $kpi['revenue'],
            ],
        ));
    }
}
