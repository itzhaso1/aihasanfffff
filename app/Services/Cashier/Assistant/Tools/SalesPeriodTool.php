<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Pos\PosAnalyticsService;

final class SalesPeriodTool extends AbstractPeriodCashierTool
{
    public function __construct(
        CashierAssistantPeriodResolver $periodResolver,
        private readonly PosAnalyticsService $posAnalytics,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'sales.period';
    }

    public function description(): string
    {
        return 'POS invoice sales total for a date range or preset (today, week, month).';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function validateArguments(array $arguments): array
    {
        if (! isset($arguments['preset']) && ! isset($arguments['from']) && ! isset($arguments['to'])) {
            $arguments['preset'] = 'month';
        }

        return parent::validateArguments($arguments);
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $sales = $this->posAnalytics->salesTotal($workspace, $period['from'], $period['to']);

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'pos_cashier_invoices',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'value' => $sales['total'],
                'invoices_count' => $sales['invoices_count'],
                'preset' => $period['label'],
            ],
        ));
    }
}
