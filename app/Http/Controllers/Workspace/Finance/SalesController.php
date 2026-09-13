<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Customer;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\SalesService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesController extends FinanceBaseController
{
    public function __construct(
        private readonly SalesService $salesService,
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.sales.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $filters = [
            'search' => $request->string('search')->toString(),
            'invoice_status' => $request->string('invoice_status')->toString(),
            'payment_status' => $request->string('payment_status')->toString(),
            'status' => $request->string('status')->toString(),
            'customer_id' => $request->integer('customer_id') ?: null,
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $invoices = $this->salesService->paginateInvoices($filters, 20);
        $summary = $this->salesService->summary($filters);
        $recentPayments = $this->salesService->recentPayments(12);

        return view('workspace.finance.modules.sales', [
            'invoices' => $invoices,
            'summary' => $summary,
            'recentPayments' => $recentPayments,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }
}
