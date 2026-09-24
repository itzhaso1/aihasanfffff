<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\CustomerStatementService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Validator;

final class CustomersStatementTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;

    public function __construct(
        private readonly FinanceAssistantPeriodResolver $periodResolver,
        private readonly CustomerStatementService $customerStatementService,
    ) {}

    public function name(): string
    {
        return 'customers.statement';
    }

    public function description(): string
    {
        return 'Customer account statement for a period with opening/closing balance and activity lines.';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function parameterSchema(): array
    {
        return [
            'customer_name' => 'required customer name or partial name',
            'query' => 'optional alias for customer_name',
            'preset' => 'optional: today|week|month|last_month',
            'from' => 'optional ISO date',
            'to' => 'optional ISO date',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'customer_name' => ['nullable', 'string', 'max:120'],
            'query' => ['nullable', 'string', 'max:120'],
            'preset' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $name = trim((string) ($validated['customer_name'] ?? $validated['query'] ?? ''));
        if ($name === '') {
            Validator::validate(['customer_name' => ''], ['customer_name' => ['required']]);
        }

        $period = $this->periodResolver->resolve(
            $validated['preset'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return [
            'customer_name' => $name,
            'period' => $period,
        ];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $resolved = $this->resolveSingleCustomer($workspace, (string) $arguments['customer_name']);
        $period = $arguments['period'];

        if ($resolved['status'] === 'not_found') {
            return FinanceAssistantToolResult::unavailable($this->name(), 'not_found', [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $period['from'], 'to' => $period['to']],
                'source' => 'customer_statement',
                'query' => $arguments['customer_name'],
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $period['from'], 'to' => $period['to']],
                'source' => 'customer_statement',
                'available' => true,
                'status' => 'ambiguous',
                'query' => $arguments['customer_name'],
                'candidates' => $resolved['candidates'],
            ]);
        }

        $statement = $this->customerStatementService->build(
            $workspace,
            $resolved['customer'],
            $period['from'],
            $period['to'],
        );

        $lines = array_slice($statement['lines'] ?? [], 0, 20);

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'source' => 'customer_statement',
            'available' => true,
            'customer' => $this->customerRow($resolved['customer']),
            'opening_balance' => Money::of($statement['opening_balance'] ?? 0),
            'closing_balance' => Money::of($statement['closing_balance'] ?? 0),
            'invoices_total' => Money::of($statement['invoices_total'] ?? 0),
            'payments_total' => Money::of($statement['payments_total'] ?? 0),
            'lines' => $lines,
        ]);
    }
}
