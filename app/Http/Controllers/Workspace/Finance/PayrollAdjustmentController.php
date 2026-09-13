<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinancePayrollRun;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\PayrollAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PayrollAdjustmentController extends FinanceBaseController
{
    public function __construct(
        private readonly PayrollAdjustmentService $payrollAdjustmentService,
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function allowances(Request $request): View
    {
        return $this->renderTypePage($request, 'allowance', 'البدلات');
    }

    public function bonuses(Request $request): View
    {
        return $this->renderTypePage($request, 'bonus', 'المكافآت');
    }

    public function deductions(Request $request): View
    {
        return $this->renderTypePage($request, 'deduction', 'الخصومات');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.adjustments.manage');
        $workspace = $this->currentWorkspace();
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

        try {
            $this->payrollAdjustmentService->create(
                workspace: $workspace,
                type: $validated['type'],
                payload: $validated,
                actorUserId: (int) $request->user()?->id
            );
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم حفظ الحركة بنجاح.');
    }

    public function approve(Request $request, FinancePayrollAdjustment $adjustment): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.adjustments.manage');
        try {
            $this->payrollAdjustmentService->approve($adjustment, (int) $request->user()?->id);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم اعتماد الحركة.');
    }

    public function post(Request $request, FinancePayrollAdjustment $adjustment): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.adjustments.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());
        $request->validate([
            'payroll_run_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_payroll_runs', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id)
                ),
            ],
        ]);

        $payrollRun = null;
        if ($request->filled('payroll_run_id')) {
            $payrollRun = FinancePayrollRun::query()->whereKey($request->integer('payroll_run_id'))->first();
        }

        try {
            $this->payrollAdjustmentService->post($adjustment, (int) $request->user()?->id, $payrollRun);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم ترحيل الحركة محاسبيًا.');
    }

    public function cancel(Request $request, FinancePayrollAdjustment $adjustment): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.adjustments.manage');
        try {
            $this->payrollAdjustmentService->cancel($adjustment);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إلغاء الحركة.');
    }

    private function renderTypePage(Request $request, string $type, string $title): View
    {
        $this->authorizeFinance($request, 'finance.adjustments.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        $status = trim((string) $request->string('status', ''));
        $search = trim((string) $request->string('search', ''));

        $adjustments = FinancePayrollAdjustment::query()
            ->with(['financeEmployee', 'user', 'postedJournalEntry', 'payrollRun'])
            ->where('type', $type)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', '%'.$search.'%')
                        ->orWhereHas('financeEmployee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'))
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('effective_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $employees = FinanceEmployee::query()
            ->where('status', 'active')
            ->orderBy('full_name')
            ->orderBy('employee_code')
            ->get();

        $runs = FinancePayrollRun::query()
            ->latest('period_month')
            ->limit(24)
            ->get();

        return view('workspace.finance.modules.payroll-adjustments', [
            'title' => $title,
            'type' => $type,
            'adjustments' => $adjustments,
            'employees' => $employees,
            'runs' => $runs,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }
}
