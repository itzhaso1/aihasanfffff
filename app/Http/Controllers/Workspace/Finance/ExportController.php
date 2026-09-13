<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\FinanceExportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends FinanceBaseController
{
    public function __construct(
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceExportService $financeExportService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());

        return view('workspace.finance.exports.index');
    }

    public function download(Request $request, string $dataset): StreamedResponse
    {
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $permission = match ($dataset) {
            'invoices', 'customers' => 'invoices.view',
            'payments' => 'payments.view',
            'expenses' => 'expenses.view',
            'quotes' => 'quotes.view',
            default => abort(404),
        };
        $this->authorizeFinance($request, $permission);

        return match ($dataset) {
            'invoices' => $this->financeExportService->invoices(
                $workspace,
                in_array($request->string('type')->toString(), ['sales', 'purchase'], true)
                    ? $request->string('type')->toString()
                    : null
            ),
            'payments' => $this->financeExportService->payments($workspace),
            'customers' => $this->financeExportService->customers($workspace),
            'expenses' => $this->financeExportService->expenses($workspace),
            'quotes' => $this->financeExportService->quotes($workspace),
            default => abort(404),
        };
    }
}
