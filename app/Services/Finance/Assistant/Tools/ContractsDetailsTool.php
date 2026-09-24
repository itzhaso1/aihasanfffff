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

final class ContractsDetailsTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;
    use ValidatesAssistantContractQuery;

    public function __construct(
        private readonly ContractBalanceService $contractBalanceService,
    ) {}

    public function name(): string
    {
        return 'contracts.details';
    }

    public function description(): string
    {
        return 'Contract details with billing balance summary.';
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

        $contract = $resolved['contract'];
        $summary = $this->contractBalanceService->summarize($contract);

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'contracts',
            'available' => true,
            'status' => 'found',
            'contract' => $this->contractDetailRow($contract),
            'balance' => $this->contractBalancePayload($summary),
            'invoices_count' => $summary['invoices']->count(),
            'payments_count' => $summary['payments']->count(),
        ]);
    }
}
