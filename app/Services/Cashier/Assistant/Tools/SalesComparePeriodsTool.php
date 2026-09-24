<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Cashier\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Cashier\Assistant\Contracts\CashierAssistantTool;
use App\Services\Pos\PosAnalyticsService;
use Illuminate\Support\Facades\Validator;

final class SalesComparePeriodsTool implements CashierAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        private readonly CashierAssistantPeriodResolver $periodResolver,
        private readonly PosAnalyticsService $posAnalytics,
    ) {}

    public function name(): string
    {
        return 'sales.compare_periods';
    }

    public function description(): string
    {
        return 'Compare POS sales between two periods (default: this month vs last month).';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function parameterSchema(): array
    {
        return [
            'preset' => 'optional: month_vs_last_month',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        Validator::validate($arguments, [
            'preset' => ['nullable', 'string', 'max:64'],
        ]);

        $current = $this->periodResolver->resolve('month');
        $previous = $this->periodResolver->resolve('last_month');

        return [
            'current' => $current,
            'previous' => $previous,
        ];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $current = $arguments['current'];
        $previous = $arguments['previous'];
        $comparison = $this->posAnalytics->compareSalesPeriods(
            $workspace,
            $current['from'],
            $current['to'],
            $previous['from'],
            $previous['to'],
        );

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'pos_cashier_invoices',
            $current['from'],
            $current['to'],
            [
                'available' => true,
                'comparison' => $comparison,
            ],
        ));
    }
}
