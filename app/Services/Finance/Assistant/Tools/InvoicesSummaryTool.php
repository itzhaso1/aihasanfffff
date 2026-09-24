<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\Assistant\FinancialAssistantMetricRegistry;
use App\Services\Finance\InvoiceInboxService;

final class InvoicesSummaryTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly InvoiceInboxService $invoiceInboxService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'invoices.summary';
    }

    public function description(): string
    {
        return 'Issued sales invoice totals for a period (invoice sales, not ledger revenue).';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);

        $query = FinanceInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'sales')
            ->whereIssued()
            ->whereDate('issue_date', '>=', $period['from'])
            ->whereDate('issue_date', '<=', $period['to']);

        $totals = $this->invoiceInboxService->filteredTotals($query);
        $count = (clone $query)->count();

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            FinancialAssistantMetricRegistry::INVOICE_SALES_TOTAL,
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'count' => $count,
                'total' => $totals['total'],
                'amount_due' => $totals['due'],
                'overdue' => $totals['overdue'],
            ],
        ));
    }
}
