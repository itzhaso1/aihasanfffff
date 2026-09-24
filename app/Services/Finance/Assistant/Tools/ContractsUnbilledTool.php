<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Contracts\ContractBalanceService;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Concerns\ValidatesAssistantContractQuery;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Support\Money\Money;

final class ContractsUnbilledTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;
    use ValidatesAssistantContractQuery;

    public function __construct(
        private readonly ContractBalanceService $contractBalanceService,
    ) {}

    public function name(): string
    {
        return 'contracts.unbilled';
    }

    public function description(): string
    {
        return 'Remaining unbilled amount on a contract.';
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
                'source' => 'contract_unbilled',
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'contract_unbilled',
                'available' => true,
                'status' => 'ambiguous',
                'candidates' => $resolved['candidates'],
            ]);
        }

        $summary = $this->contractBalanceService->summarize($resolved['contract']);

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'contract_unbilled',
            'available' => true,
            'contract' => $this->contractRow($resolved['contract']),
            'contract_value' => Money::of($summary['contract_value'] ?? 0),
            'invoiced' => Money::of($summary['invoiced'] ?? 0),
            'value' => Money::of($summary['unbilled'] ?? 0),
        ]);
    }
}
