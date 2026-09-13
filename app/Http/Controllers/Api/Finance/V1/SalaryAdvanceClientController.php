<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\SalaryAdvanceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalaryAdvanceClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly SalaryAdvanceService $salaryAdvanceService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.salary_advances.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:open,closed'],
            'type' => ['nullable', 'in:salary_advance,employee_loan'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = trim((string) ($validated['status'] ?? ''));
        $type = trim((string) ($validated['type'] ?? ''));
        $search = trim((string) ($validated['search'] ?? ''));

        $page = FinanceSalaryAdvance::query()
            ->with(['financeEmployee', 'repayments.treasuryAccount'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('notes', 'like', '%'.$search.'%')
                        ->orWhereHas('financeEmployee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('issued_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinanceSalaryAdvance $advance) => $this->presenter->salaryAdvance($advance))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinanceSalaryAdvance $advance): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.salary_advances.view');
        abort_unless((int) $advance->workspace_id === (int) $workspace->id, 404);

        $advance->load(['financeEmployee', 'repayments.treasuryAccount']);

        return $this->ok($this->presenter->salaryAdvance($advance));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.salary_advances.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'issued_at' => ['required', 'date'],
            'type' => ['required', 'in:salary_advance,employee_loan'],
            'payment_method' => ['nullable', 'string', 'max:32'],
            'treasury_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_treasury_accounts', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id)
                ),
            ],
            'notes' => ['nullable', 'string'],
        ]);

        $advance = $this->runFinanceDomain(
            fn () => $this->salaryAdvanceService->issue($workspace, $validated, (int) $request->user()?->id)
        );

        return $this->ok(
            $this->presenter->salaryAdvance($advance->load(['financeEmployee', 'repayments'])),
            message: 'تم تسجيل السلفة وترحيل القيد المحاسبي بنجاح.',
            status: 201,
        );
    }

    public function repay(Request $request, FinanceSalaryAdvance $advance): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.salary_advances.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        abort_unless((int) $advance->workspace_id === (int) $workspace->id, 404);

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,bank_transfer,card,other,payroll_deduction'],
            'treasury_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_treasury_accounts', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id)
                ),
            ],
            'notes' => ['nullable', 'string'],
        ]);

        $this->runFinanceDomain(
            fn () => $this->salaryAdvanceService->recordRepayment($advance, $validated, (int) $request->user()?->id)
        );

        return $this->ok(
            $this->presenter->salaryAdvance($advance->fresh()->load(['financeEmployee', 'repayments'])),
            message: 'تم تسجيل سداد السلفة وتحديث الأرصدة.',
        );
    }
}
