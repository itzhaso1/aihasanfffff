<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Pos\PosAnalyticsService;

final class SalesPaymentMethodsTool extends AbstractPeriodCashierTool
{
    public function __construct(
        CashierAssistantPeriodResolver $periodResolver,
        private readonly PosAnalyticsService $posAnalytics,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'sales.payment_methods';
    }

    public function description(): string
    {
        return 'POS sales grouped by payment method (cash, card, etc.).';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);
        $result = $this->posAnalytics->paymentMethods($workspace, $period['from'], $period['to']);

        if (! $result['available']) {
            return CashierAssistantToolResult::unavailable(
                $this->name(),
                (string) ($result['reason'] ?? 'Payment method data is insufficient for this period.')
            );
        }

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'pos_orders',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'items' => $result['methods'],
            ],
        ));
    }
}
