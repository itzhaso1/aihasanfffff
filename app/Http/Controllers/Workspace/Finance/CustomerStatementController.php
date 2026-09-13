<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Customer;
use App\Services\Finance\CustomerStatementService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\FinanceExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class CustomerStatementController extends FinanceBaseController
{
    public function __construct(
        private readonly CustomerStatementService $customerStatementService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceExportService $financeExportService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());

        return view('workspace.finance.statements.index', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'statement' => null,
        ]);
    }

    public function show(Request $request): View|Response
    {
        $this->authorizeFinance($request, 'invoices.view');
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $customer = Customer::query()->whereKey($validated['customer_id'])->firstOrFail();
        $statement = $this->customerStatementService->build(
            $workspace,
            $customer,
            $validated['from'],
            $validated['to']
        );

        if ($request->boolean('csv')) {
            return $this->financeExportService->statement(
                $workspace,
                $customer,
                $validated['from'],
                $validated['to']
            );
        }

        if ($request->boolean('pdf')) {
            if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                throw new RuntimeException('PDF generation is unavailable.');
            }

            $html = view('workspace.finance.statements.pdf', ['statement' => $statement])->render();

            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4')->download(
                'statement-'.$customer->id.'-'.$validated['from'].'.pdf'
            );
        }

        return view('workspace.finance.statements.index', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'statement' => $statement,
        ]);
    }
}
