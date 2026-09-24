<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\LedgerReportService;

final class ProfitNetTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
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
        return 'Net profit from posted ledger accounts for a period.';
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
                'value' => $pnl['net_profit'],
                'metric_kind' => 'ledger_net_profit',
                'label_ar' => 'صافي الربح من الأستاذ المرحّل',
                'label_en' => 'Net profit from posted ledger P&L',
                'operating_expenses' => $pnl['operating_expenses'],
                'operating_expenses_metric_kind' => 'ledger_operating_expenses',
                'operating_expenses_label_ar' => 'مصروفات التشغيل المرحّلة في الأرباح والخسائر',
                'operating_expenses_label_en' => 'Posted ledger operating expenses (P&L)',
                'note' => 'Based on posted ledger entries; not the expense-module total.',
            ],
        ));
    }
}
