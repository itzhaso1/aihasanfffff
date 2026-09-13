<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceInvoicePayment;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\DashboardService;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly DashboardService $dashboardService,
        private readonly FinanceAnalyticsService $financeAnalyticsService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'lifecycle' => ['nullable', 'string', 'max:32'],
            'payment_method' => ['nullable', 'string', 'max:32'],
        ]);

        $metrics = $this->dashboardService->metrics();
        $paidThisPeriod = $this->presenter->money(
            FinanceInvoicePayment::query()
                ->where(function ($query): void {
                    $query->where('status', FinanceInvoicePayment::STATUS_POSTED)
                        ->orWhereNull('status')
                        ->orWhere('status', '');
                })
                ->whereDate('payment_date', '>=', now()->startOfMonth()->toDateString())
                ->whereDate('payment_date', '<=', now()->endOfMonth()->toDateString())
                ->sum('amount')
        );

        $payload = $this->presenter->dashboard($metrics, $paidThisPeriod);
        $payload['analytics'] = $this->presenter->analytics(
            $this->financeAnalyticsService->dashboard((int) $workspace->id, $filters)
        );

        return $this->ok($payload);
    }
}
