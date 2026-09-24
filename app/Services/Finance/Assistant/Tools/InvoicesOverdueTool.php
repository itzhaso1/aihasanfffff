<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Concerns\ResolvesAssistantEntities;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\InvoiceInboxService;
use App\Support\Finance\InvoicePresentation;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Validator;

final class InvoicesOverdueTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;
    use ResolvesAssistantEntities;

    public function __construct(
        private readonly InvoiceInboxService $invoiceInboxService,
    ) {}

    public function name(): string
    {
        return 'invoices.overdue';
    }

    public function description(): string
    {
        return 'List overdue sales invoices with count and total outstanding.';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function parameterSchema(): array
    {
        return [
            'limit' => 'optional integer 1-20 for listed invoices',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return [
            'limit' => (int) ($validated['limit'] ?? 10),
        ];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $today = now()->toDateString();
        $limit = max(1, min(20, (int) $arguments['limit']));

        $query = FinanceInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'sales');
        $this->invoiceInboxService->applyLifecycle($query, InvoicePresentation::LIFECYCLE_OVERDUE);

        $count = (clone $query)->count();
        $totalDue = Money::of((clone $query)->sum('amount_due'));

        $invoices = (clone $query)
            ->with('customer')
            ->orderBy('due_date')
            ->limit($limit)
            ->get()
            ->map(fn (FinanceInvoice $invoice): array => $this->invoiceRow($invoice))
            ->all();

        return FinanceAssistantToolResult::available($this->name(), [
            'currency' => $this->workspaceCurrency($workspace),
            'period' => ['from' => $today, 'to' => $today],
            'source' => 'invoice_outstanding',
            'available' => true,
            'count' => $count,
            'total_due' => $totalDue,
            'invoices' => $invoices,
        ]);
    }
}
