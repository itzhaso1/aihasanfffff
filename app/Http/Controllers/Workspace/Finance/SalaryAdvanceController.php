<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\SalaryAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalaryAdvanceController extends FinanceBaseController
{
    public function __construct(
        private readonly SalaryAdvanceService $salaryAdvanceService,
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.salary_advances.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        $status = trim((string) $request->string('status', ''));
        $type = trim((string) $request->string('type', ''));
        $search = trim((string) $request->string('search', ''));

        $advances = FinanceSalaryAdvance::query()
            ->with(['financeEmployee', 'user', 'repayments.treasuryAccount'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('notes', 'like', '%'.$search.'%')
                        ->orWhereHas('financeEmployee', fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', '%'.$search.'%'))
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('issued_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $employees = FinanceEmployee::query()
            ->where('status', 'active')
            ->orderBy('full_name')
            ->orderBy('employee_code')
            ->get();

        $treasuryAccounts = FinanceTreasuryAccount::query()
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('workspace.finance.modules.salary-advances', [
            'advances' => $advances,
            'employees' => $employees,
            'treasuryAccounts' => $treasuryAccounts,
            'filters' => [
                'status' => $status,
                'type' => $type,
                'search' => $search,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.salary_advances.manage');
        $workspace = $this->currentWorkspace();
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

        try {
            $this->salaryAdvanceService->issue($workspace, $validated, (int) $request->user()?->id);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تسجيل السلفة وترحيل القيد المحاسبي بنجاح.');
    }

    public function repay(Request $request, FinanceSalaryAdvance $advance): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.salary_advances.manage');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
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

        try {
            $this->salaryAdvanceService->recordRepayment($advance, $validated, (int) $request->user()?->id);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تسجيل سداد السلفة وتحديث الأرصدة.');
    }
}
