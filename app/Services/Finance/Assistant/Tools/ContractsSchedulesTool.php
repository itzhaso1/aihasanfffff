<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceBillingSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contracts\ContractBalanceService;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Concerns\ValidatesAssistantContractQuery;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;

final class ContractsSchedulesTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;
    use ValidatesAssistantContractQuery;

    public function __construct(
        private readonly ContractBalanceService $contractBalanceService,
    ) {}

    public function name(): string
    {
        return 'contracts.schedules';
    }

    public function description(): string
    {
        return 'Billing schedules linked to a contract.';
    }

    public function permission(): string
    {
        return 'contracts.view';
    }

    public function parameterSchema(): array
    {
        return [
            'contract_number' => 'optional contract number',
            'query' => 'optional alias for contract_number',
            'customer_name' => 'optional customer name',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        return $this->validatedContractQuery($arguments);
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $resolved = $this->resolveSingleContract(
            $workspace,
            $arguments['contract_number'],
            $arguments['customer_name'],
        );
        $today = now()->toDateString();

        if ($resolved['status'] === 'not_found') {
            return FinanceAssistantToolResult::unavailable($this->name(), 'not_found', [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'billing_schedules',
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'billing_schedules',
                'available' => true,
                'status' => 'ambiguous',
                'candidates' => $resolved['candidates'],
            ]);
        }

        $contract = $resolved['contract'];
        $schedules = FinanceBillingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('contract_id', $contract->id)
            ->orderBy('next_run_on')
            ->get()
            ->map(function (FinanceBillingSchedule $schedule): array {
                $row = $this->billingScheduleRow($schedule);
                $row['schedule_status'] = $this->contractBalanceService->scheduleStatus($schedule);

                return $row;
            })
            ->all();

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'billing_schedules',
            'available' => true,
            'contract' => $this->contractRow($contract),
            'count' => count($schedules),
            'schedules' => $schedules,
        ]);
    }
}
