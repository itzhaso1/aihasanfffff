<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Customer;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\CustomerStatementService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\FinanceExportService;
use App\Support\Tenancy\WorkspaceContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StatementController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly CustomerStatementService $customerStatementService,
        private readonly FinanceExportService $financeExportService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse|Response
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv,pdf'],
        ]);

        $customer = Customer::query()->whereKey($validated['customer_id'])->firstOrFail();
        $format = $validated['format'] ?? 'json';

        if ($format === 'csv') {
            return $this->financeExportService->statement(
                $workspace,
                $customer,
                $validated['from'],
                $validated['to']
            );
        }

        $statement = $this->customerStatementService->build(
            $workspace,
            $customer,
            $validated['from'],
            $validated['to']
        );

        if ($format === 'pdf') {
            if (! class_exists(Pdf::class)) {
                throw new RuntimeException('PDF generation is unavailable.');
            }

            $html = view('workspace.finance.statements.pdf', ['statement' => $statement])->render();

            return Pdf::loadHTML($html)->setPaper('a4')->download(
                'statement-'.$customer->id.'-'.$validated['from'].'.pdf'
            );
        }

        return $this->ok($this->presenter->statement($statement));
    }
}
