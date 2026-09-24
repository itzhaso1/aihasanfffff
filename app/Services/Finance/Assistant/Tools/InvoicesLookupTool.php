<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use Illuminate\Support\Facades\Validator;

final class InvoicesLookupTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;

    public function name(): string
    {
        return 'invoices.lookup';
    }

    public function description(): string
    {
        return 'Find sales invoices by invoice number or partial number (e.g. INV-1042).';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function parameterSchema(): array
    {
        return [
            'invoice_number' => 'required invoice number or partial reference',
            'query' => 'optional alias for invoice_number',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'query' => ['nullable', 'string', 'max:64'],
        ]);

        $number = trim((string) ($validated['invoice_number'] ?? $validated['query'] ?? ''));
        if ($number === '') {
            Validator::validate(['invoice_number' => ''], ['invoice_number' => ['required']]);
        }

        return ['invoice_number' => $number];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $resolved = $this->resolveSingleInvoice($workspace, (string) $arguments['invoice_number']);
        $today = now()->toDateString();

        if ($resolved['status'] === 'not_found') {
            return FinanceAssistantToolResult::unavailable($this->name(), 'not_found', [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'finance_invoices',
                'query' => $arguments['invoice_number'],
            ]);
        }

        if ($resolved['status'] === 'ambiguous') {
            return FinanceAssistantToolResult::available($this->name(), [
                'currency' => $this->workspaceCurrency($workspace),
                'period' => ['from' => $today, 'to' => $today],
                'source' => 'finance_invoices',
                'available' => true,
                'status' => 'ambiguous',
                'query' => $arguments['invoice_number'],
                'candidates' => $resolved['candidates'],
            ]);
        }

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'finance_invoices',
            'available' => true,
            'status' => 'found',
            'invoice' => $this->invoiceRow($resolved['invoice']),
        ]);
    }
}
