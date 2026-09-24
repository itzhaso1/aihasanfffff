<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceEmployee;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use Illuminate\Support\Facades\Validator;

final class PayrollEmployeesCountTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;

    public function name(): string
    {
        return 'payroll.employees_count';
    }

    public function description(): string
    {
        return 'Count active company employees in the finance workspace.';
    }

    public function permission(): string
    {
        return 'payroll.view';
    }

    public function parameterSchema(): array
    {
        return [
            'status' => 'optional: active|inactive|all (default active)',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        return Validator::validate($arguments, [
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
        ]);
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $status = (string) ($arguments['status'] ?? 'active');
        if ($status === '') {
            $status = 'active';
        }

        $query = FinanceEmployee::query()->where('workspace_id', $workspace->id);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $today = now()->toDateString();

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'company_employees',
            $today,
            $today,
            [
                'available' => true,
                'count' => (int) (clone $query)->count(),
                'status' => $status,
                'label_ar' => $status === 'all' ? 'إجمالي موظفي الشركة' : 'موظفو الشركة النشطون',
                'label_en' => $status === 'all' ? 'Total company employees' : 'Active company employees',
            ],
        ));
    }
}
