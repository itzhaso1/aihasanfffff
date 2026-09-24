<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;

final class PayrollTotalTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly PayrollPeriodTool $payrollPeriodTool,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'payroll.total';
    }

    public function description(): string
    {
        return 'Paid payroll total for the current month by default.';
    }

    public function permission(): string
    {
        return 'payroll.view';
    }

    public function validateArguments(array $arguments): array
    {
        if (! isset($arguments['preset']) && ! isset($arguments['from']) && ! isset($arguments['to'])) {
            $arguments['preset'] = 'month';
        }

        return parent::validateArguments($arguments);
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $result = $this->payrollPeriodTool->execute($workspace, $user, $arguments);

        return FinanceAssistantToolResult::available($this->name(), [
            ...$result->data,
        ]);
    }
}
