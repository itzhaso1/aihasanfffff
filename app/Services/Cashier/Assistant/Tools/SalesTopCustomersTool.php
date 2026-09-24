<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Pos\PosAnalyticsService;

final class SalesTopCustomersTool extends AbstractPeriodCashierTool
{
    public function __construct(
        CashierAssistantPeriodResolver $periodResolver,
        private readonly PosAnalyticsService $posAnalytics,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'sales.top_customers';
    }

    public function description(): string
    {
        return 'Top POS customers by revenue for a period.';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $limit = (int) ($arguments['limit'] ?? 10);
        $items = $this->posAnalytics->topCustomers($workspace, $period['from'], $period['to'], $limit);

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'pos_orders',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'items' => $items,
            ],
        ));
    }
}
