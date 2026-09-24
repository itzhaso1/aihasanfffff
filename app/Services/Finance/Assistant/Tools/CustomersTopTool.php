<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\FinanceAnalyticsService;

final class CustomersTopTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly FinanceAnalyticsService $analyticsService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'customers.top';
    }

    public function description(): string
    {
        return 'Top customers by revenue for a period.';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $limit = (int) ($arguments['limit'] ?? 10);
        $dashboard = $this->analyticsService->dashboard((int) $workspace->id, [
            'from' => $period['from'],
            'to' => $period['to'],
        ]);

        $rawCustomers = $dashboard['top_customers'] ?? [];
        $customerRows = is_array($rawCustomers) ? $rawCustomers : $rawCustomers->all();
        $items = array_slice($customerRows, 0, max(1, min(50, $limit)));

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_analytics',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'items' => $items,
            ],
        ));
    }
}
