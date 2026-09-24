<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\LedgerReportService;

final class ProfitCogsTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly LedgerReportService $ledgerReportService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'profit.cogs';
    }

    public function description(): string
    {
        return 'Cost of goods sold from posted ledger accounts for a period.';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $pnl = $this->ledgerReportService->profitAndLoss((int) $workspace->id, $period['from'], $period['to']);

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'ledger_profit_and_loss',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => $pnl['cogs'],
            ],
        ));
    }
}
