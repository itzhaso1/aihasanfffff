<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use Illuminate\Support\Facades\Validator;

class InvoicesByCustomerTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;

    public function name(): string
    {
        return 'invoices.by_customer';
    }

    public function description(): string
    {
        return 'List sales invoices for a customer by name.';
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
            'limit' => 'optional integer 1-20',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'customer_name' => ['nullable', 'string', 'max:120'],
            'query' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $name = trim((string) ($validated['customer_name'] ?? $validated['query'] ?? ''));
        if ($name === '') {
            Validator::validate(['customer_name' => ''], ['customer_name' => ['required']]);
        }

        return [
            'customer_name' => $name,
            'limit' => (int) ($validated['limit'] ?? 10),
        ];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $resolved = $this->resolveSingleCustomer($workspace, (string) $arguments['customer_name']);
        $today = now()->toDateString();

        if ($resolved['status'] === 'not_found') {
            return FinanceAssistantToolResult::unavailable($this->name(), 'not_found', [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'finance_invoices',
                'query' => $arguments['customer_name'],
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'finance_invoices',
                'available' => true,
                'status' => 'ambiguous',
                'query' => $arguments['customer_name'],
                'candidates' => $resolved['candidates'],
            ]);
        }

        $customer = $resolved['customer'];
        $limit = max(1, min(20, (int) $arguments['limit']));

        $invoices = FinanceInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'sales')
            ->where('customer_id', $customer->id)
            ->whereIssued()
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
            'customer' => $this->customerRow($customer),
            'count' => count($invoices),
            'invoices' => $invoices,
        ]);
    }
}
