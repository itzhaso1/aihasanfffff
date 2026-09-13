<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Services\Finance\FinanceBootstrapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceEmployeeController extends FinanceBaseController
{
    public function __construct(
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'payroll.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $search = trim((string) $request->string('search'));
        $status = trim((string) $request->string('status'));
        $jobTitle = trim((string) $request->string('job_title'));

        $employees = FinanceEmployee::query()
            ->withCount('payrollRecords')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('employee_code', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($jobTitle !== '', fn ($query) => $query->where('job_title', 'like', '%'.$jobTitle.'%'))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('workspace.finance.modules.employees', [
            'employees' => $employees,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'job_title' => $jobTitle,
            ],
            'statusLabels' => [
                'active' => 'نشط',
                'inactive' => 'غير نشط',
                'suspended' => 'موقوف',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'payroll.manage');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate($this->employeeRules($workspace->id));
        $employeeCode = trim((string) ($validated['employee_code'] ?? ''));

        FinanceEmployee::query()->create([
            'workspace_id' => $workspace->id,
            'employee_code' => $employeeCode !== '' ? $employeeCode : $this->nextEmployeeCode($workspace->id),
            'full_name' => (string) $validated['full_name'],
            'job_title' => $validated['job_title'] ?? null,
            'basic_salary' => (float) ($validated['basic_salary'] ?? 0),
            'hire_date' => $validated['hire_date'] ?? null,
            'status' => (string) ($validated['status'] ?? 'active'),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'emergency_contact' => $validated['emergency_contact'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'تمت إضافة موظف المالية بنجاح.');
    }

    public function show(Request $request, FinanceEmployee $employee): View
    {
        $this->authorizeFinance($request, 'payroll.view');
        $workspace = $this->currentWorkspace();
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $employee->load(['payrollRecords' => fn ($query) => $query->latest('period_start')->limit(24)]);

        return view('workspace.finance.modules.employee-details', [
            'employee' => $employee,
            'statusLabels' => [
                'active' => 'نشط',
                'inactive' => 'غير نشط',
                'suspended' => 'موقوف',
            ],
            'payrollStatusLabels' => [
                'draft' => 'مسودة',
                'pending' => 'قيد الانتظار',
                'paid' => 'مدفوع',
                'partial' => 'مدفوع جزئيًا',
                'cancelled' => 'ملغي',
            ],
        ]);
    }

    public function update(Request $request, FinanceEmployee $employee): RedirectResponse
    {
        $this->authorizeFinance($request, 'payroll.manage');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $validated = $request->validate($this->employeeRules($workspace->id, (int) $employee->id));
        $employeeCode = trim((string) ($validated['employee_code'] ?? ''));
        $employee->update([
            'employee_code' => $employeeCode !== '' ? $employeeCode : $employee->employee_code,
            'full_name' => (string) $validated['full_name'],
            'job_title' => $validated['job_title'] ?? null,
            'basic_salary' => (float) ($validated['basic_salary'] ?? 0),
            'hire_date' => $validated['hire_date'] ?? null,
            'status' => (string) ($validated['status'] ?? 'active'),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'emergency_contact' => $validated['emergency_contact'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'تم تحديث موظف المالية.');
    }

    public function destroy(Request $request, FinanceEmployee $employee): RedirectResponse
    {
        $this->authorizeFinance($request, 'payroll.manage');
        $workspace = $this->currentWorkspace();
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $employee->delete();

        return redirect()->route('workspace.finance.employees.index')->with('success', 'تم حذف سجل الموظف من المالية.');
    }

    public function storePayrollRecord(Request $request, FinanceEmployee $employee): RedirectResponse
    {
        $this->authorizeFinance($request, 'payroll.manage');
        $workspace = $this->currentWorkspace();
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'allowances_total' => ['nullable', 'numeric', 'min:0'],
            'deductions_total' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['required', 'in:draft,pending,paid,partial,cancelled'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $basicSalary = (float) ($validated['basic_salary'] ?? $employee->basic_salary);
        $allowances = (float) ($validated['allowances_total'] ?? 0);
        $deductions = (float) ($validated['deductions_total'] ?? 0);
        $gross = round($basicSalary + $allowances, 2);
        $net = round(max(0, $gross - $deductions), 2);

        FinanceEmployeePayrollRecord::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'finance_employee_id' => $employee->id,
                'period_start' => $validated['period_start'],
                'period_end' => $validated['period_end'],
            ],
            [
                'basic_salary' => $basicSalary,
                'allowances_total' => $allowances,
                'deductions_total' => $deductions,
                'gross_amount' => $gross,
                'net_amount' => $net,
                'payment_status' => $validated['payment_status'],
                'paid_at' => $validated['paid_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]
        );

        return back()->with('success', 'تم حفظ سجل الاستحقاق للفترة المحددة.');
    }

    /**
     * @return array<string,mixed>
     */
    private function employeeRules(int $workspaceId, ?int $ignoreEmployeeId = null): array
    {
        return [
            'employee_code' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('finance_employees', 'employee_code')
                    ->where(fn ($query) => $query->where('workspace_id', $workspaceId))
                    ->ignore($ignoreEmployeeId),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function nextEmployeeCode(int $workspaceId): string
    {
        $last = FinanceEmployee::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->value('employee_code');

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;

            return 'FEMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        }

        $count = FinanceEmployee::withoutGlobalScopes()->where('workspace_id', $workspaceId)->count() + 1;

        return 'FEMP-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }
}
