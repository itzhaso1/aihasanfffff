<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Concerns\ValidatesAssistantContractQuery;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use Illuminate\Support\Facades\Validator;

final class ContractsInvoicesTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;
    use ValidatesAssistantContractQuery;

    public function name(): string
    {
        return 'contracts.invoices';
    }

    public function description(): string
    {
        return 'Sales invoices linked to a contract.';
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
            'limit' => 'optional integer 1-20',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = $this->validatedContractQuery($arguments);
        $extra = Validator::validate($arguments, [
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return [...$validated, 'limit' => (int) ($extra['limit'] ?? 10)];
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
                'source' => 'finance_invoices',
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'finance_invoices',
                'available' => true,
                'status' => 'ambiguous',
                'candidates' => $resolved['candidates'],
            ]);
        }

        $contract = $resolved['contract'];
        $limit = max(1, min(20, (int) $arguments['limit']));

        $invoices = FinanceInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('contract_id', $contract->id)
            ->where('type', 'sales')
            ->orderByDesc('issue_date')
            ->limit($limit)
            ->get()
            ->map(fn (FinanceInvoice $invoice): array => $this->invoiceRow($invoice))
            ->all();

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'finance_invoices',
            'available' => true,
            'contract' => $this->contractRow($contract),
            'count' => count($invoices),
            'invoices' => $invoices,
        ]);
    }
}
