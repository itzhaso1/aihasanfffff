<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\CustomerBalanceService;
use Illuminate\Support\Facades\Validator;

final class CustomersLookupTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;

    public function __construct(
        private readonly CustomerBalanceService $customerBalanceService,
    ) {}

    public function name(): string
    {
        return 'customers.lookup';
    }

    public function description(): string
    {
        return 'Find a customer by name, email, or phone.';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function parameterSchema(): array
    {
        return [
            'customer_name' => 'required customer name or partial name',
            'query' => 'optional alias for customer_name',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'customer_name' => ['nullable', 'string', 'max:120'],
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        $name = trim((string) ($validated['customer_name'] ?? $validated['query'] ?? ''));
        if ($name === '') {
            Validator::validate(['customer_name' => ''], ['customer_name' => ['required']]);
        }

        return ['customer_name' => $name];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $resolved = $this->resolveSingleCustomer($workspace, (string) $arguments['customer_name']);
        $today = now()->toDateString();

        if ($resolved['status'] === 'not_found') {
            return FinanceAssistantToolResult::unavailable($this->name(), 'not_found', [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'customers',
                'query' => $arguments['customer_name'],
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'customers',
                'available' => true,
                'status' => 'ambiguous',
                'query' => $arguments['customer_name'],
                'candidates' => $resolved['candidates'],
            ]);
        }

        $customer = $resolved['customer'];
        $outstanding = $this->customerBalanceService->outstanding((int) $workspace->id, (int) $customer->id);

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'customers',
            'available' => true,
            'status' => 'found',
            'customer' => $this->customerRow($customer, $outstanding),
        ]);
    }
}
