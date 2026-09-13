<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Customer;
use App\Services\Finance\BillingDashboardService;
use App\Services\Finance\FinanceBootstrapService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingDashboardController extends FinanceBaseController
{
    public function __construct(
        private readonly BillingDashboardService $billingDashboardService,
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());

        $filters = [
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'customer_id' => $request->string('customer_id')->toString(),
            'type' => $request->string('type')->toString(),
            'currency' => strtoupper($request->string('currency')->toString()),
        ];

        return view('workspace.finance.billing.dashboard', [
            'metrics' => $this->billingDashboardService->metrics($filters),
            'filters' => $filters,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
