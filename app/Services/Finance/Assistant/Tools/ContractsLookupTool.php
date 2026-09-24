<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Concerns\ValidatesAssistantContractQuery;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;

final class ContractsLookupTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;
    use ValidatesAssistantContractQuery;

    public function name(): string
    {
        return 'contracts.lookup';
    }

    public function description(): string
    {
        return 'Find a contract by number or by customer name.';
    }

    public function permission(): string
    {
        return 'contracts.view';
    }

    public function parameterSchema(): array
    {
        return [
            'contract_number' => 'optional contract number such as CTR-000001',
            'query' => 'optional alias for contract_number',
            'customer_name' => 'optional customer name when contract number is unknown',
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
                'source' => 'contracts',
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'contracts',
                'available' => true,
                'status' => 'ambiguous',
                'candidates' => $resolved['candidates'],
            ]);
        }

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'contracts',
            'available' => true,
            'status' => 'found',
            'contract' => $this->contractRow($resolved['contract']),
        ]);
    }
}
