<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Finance\LedgerReportService;

final class ProfitNetTool extends AbstractPeriodCashierTool
{
    public function __construct(
        CashierAssistantPeriodResolver $periodResolver,
        private readonly LedgerReportService $ledgerReportService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'profit.net';
    }

    public function description(): string
    {
        return 'Net profit from posted ledger accounts for a period (not POS cash profit).';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $pnl = $this->ledgerReportService->profitAndLoss((int) $workspace->id, $period['from'], $period['to']);

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'ledger_profit_and_loss',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => $pnl['net_profit'],
                'gross_profit' => $pnl['gross_profit'],
                'revenue' => $pnl['revenue'],
                'cogs' => $pnl['cogs'],
                'note' => 'Based on posted ledger entries.',
            ],
        ));
    }
}
