<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Services\Finance\BusinessAlertService;
use App\Services\Finance\DashboardService;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\PeriodComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends FinanceBaseController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly PeriodComparisonService $periodComparisonService,
        private readonly BusinessAlertService $businessAlertService,
        private readonly FinanceAnalyticsService $financeAnalyticsService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $data = $this->dashboardService->metrics();
        $filters = [
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'customer_id' => $request->string('customer_id')->toString(),
            'product_id' => $request->string('product_id')->toString(),
            'project_id' => $request->string('project_id')->toString(),
            'lifecycle' => $request->string('lifecycle')->toString(),
            'payment_method' => $request->string('payment_method')->toString(),
        ];

        return view('workspace.finance.dashboard', [
            'workspace' => $workspace,
            'periods' => $this->periodComparisonService->compare((int) $workspace->id),
            'alerts' => $this->businessAlertService->alerts((int) $workspace->id),
            'analytics' => $this->financeAnalyticsService->dashboard((int) $workspace->id, $filters),
            'filterCustomers' => Customer::query()->orderBy('name')->limit(200)->get(['id', 'name']),
            'filterProducts' => Product::query()->orderBy('name')->limit(200)->get(['id', 'name']),
            'filterProjects' => Schema::hasTable('finance_projects')
                ? FinanceProject::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            ...$data,
        ]);
    }
}
