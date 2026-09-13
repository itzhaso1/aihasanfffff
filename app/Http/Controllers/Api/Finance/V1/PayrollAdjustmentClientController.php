<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinancePayrollRun;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\PayrollAdjustmentService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollAdjustmentClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly PayrollAdjustmentService $payrollAdjustmentService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.adjustments.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'type' => ['nullable', 'in:allowance,bonus,deduction'],
            'status' => ['nullable', 'in:draft,approved,posted,cancelled'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $type = trim((string) ($validated['type'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));
        $search = trim((string) ($validated['search'] ?? ''));

        $page = FinancePayrollAdjustment::query()
            ->with(['financeEmployee', 'postedJournalEntry', 'payrollRun'])
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('financeEmployee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('effective_date')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinancePayrollAdjustment $adjustment) => $this->presenter->payrollAdjustment($adjustment))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.adjustments.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'type' => ['required', 'in:allowance,bonus,deduction'],
            'finance_employee_id' => [
                'required',
                'integer',
                Rule::exists('finance_employees', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id)->where('status', 'active')
                ),
            ],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_users', 'user_id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id)->where('status', 'active')
                ),
            ],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'effective_date' => ['required', 'date'],
            'status' => ['nullable', 'in:draft,approved'],
            'notes' => ['nullable', 'string'],
        ]);

        $adjustment = $this->runFinanceDomain(
            fn () => $this->payrollAdjustmentService->create(
                workspace: $workspace,
                type: $validated['type'],
                payload: $validated,
                actorUserId: (int) $request->user()?->id
            )
        );

        return $this->ok(
            $this->presenter->payrollAdjustment($adjustment->load('financeEmployee')),
            message: 'تم حفظ الحركة بنجاح.',
            status: 201,
        );
    }

    public function approve(Request $request, FinancePayrollAdjustment $adjustment): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.adjustments.manage');
        abort_unless((int) $adjustment->workspace_id === (int) $workspace->id, 404);

        $updated = $this->runFinanceDomain(
            fn () => $this->payrollAdjustmentService->approve($adjustment, (int) $request->user()?->id)
        );

        return $this->ok($this->presenter->payrollAdjustment($updated->load('financeEmployee')), message: 'تم اعتماد الحركة.');
    }

    public function post(Request $request, FinancePayrollAdjustment $adjustment): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.adjustments.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        abort_unless((int) $adjustment->workspace_id === (int) $workspace->id, 404);

        $request->validate([
            'payroll_run_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_payroll_runs', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id)
                ),
            ],
        ]);

        $payrollRun = null;
        if ($request->filled('payroll_run_id')) {
            $payrollRun = FinancePayrollRun::query()->whereKey($request->integer('payroll_run_id'))->first();
        }

        $updated = $this->runFinanceDomain(
            fn () => $this->payrollAdjustmentService->post($adjustment, (int) $request->user()?->id, $payrollRun)
        );

        return $this->ok($this->presenter->payrollAdjustment($updated->load('financeEmployee')), message: 'تم ترحيل الحركة محاسبيًا.');
    }

    public function cancel(Request $request, FinancePayrollAdjustment $adjustment): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.adjustments.manage');
        abort_unless((int) $adjustment->workspace_id === (int) $workspace->id, 404);

        $updated = $this->runFinanceDomain(
            fn () => $this->payrollAdjustmentService->cancel($adjustment)
        );

        return $this->ok($this->presenter->payrollAdjustment($updated->load('financeEmployee')), message: 'تم إلغاء الحركة.');
    }
}
