<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceBillingSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contracts\ContractBalanceService;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use Illuminate\Support\Facades\Validator;

final class BillingSchedulesUpcomingTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;

    public function __construct(
        private readonly ContractBalanceService $contractBalanceService,
    ) {}

    public function name(): string
    {
        return 'billing_schedules.upcoming';
    }

    public function description(): string
    {
        return 'Upcoming and due billing schedules in the workspace.';
    }

    public function permission(): string
    {
        return 'contracts.view';
    }

    public function parameterSchema(): array
    {
        return [
            'days' => 'optional horizon in days (default 30, max 90)',
            'limit' => 'optional integer 1-20',
            'include_overdue' => 'optional boolean, default true',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'include_overdue' => ['nullable', 'boolean'],
        ]);

        return [
            'days' => (int) ($validated['days'] ?? 30),
            'limit' => (int) ($validated['limit'] ?? 10),
            'include_overdue' => array_key_exists('include_overdue', $validated)
                ? (bool) $validated['include_overdue']
                : true,
        ];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $today = now()->toDateString();
        $horizon = now()->addDays(max(1, (int) $arguments['days']))->toDateString();
        $limit = max(1, min(20, (int) $arguments['limit']));

        $query = FinanceBillingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', FinanceBillingSchedule::STATUS_ACTIVE)
            ->whereNotNull('next_run_on');

        if ($arguments['include_overdue']) {
            $query->whereDate('next_run_on', '<=', $horizon);
        } else {
            $query->whereDate('next_run_on', '>=', $today)
                ->whereDate('next_run_on', '<=', $horizon);
        }

        $schedules = $query
            ->with(['contract.customer', 'customer'])
            ->orderBy('next_run_on')
            ->limit($limit)
            ->get()
            ->map(function (FinanceBillingSchedule $schedule): array {
                $row = $this->billingScheduleRow($schedule);
                $row['schedule_status'] = $this->contractBalanceService->scheduleStatus($schedule);

                return $row;
            })
            ->all();

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $horizon],
            'source' => 'billing_schedules',
            'available' => true,
            'count' => count($schedules),
            'schedules' => $schedules,
        ]);
    }
}
