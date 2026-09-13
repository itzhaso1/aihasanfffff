<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\DashboardService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));
        $jobTitle = trim((string) ($validated['job_title'] ?? ''));

        $page = FinanceEmployee::query()
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
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinanceEmployee $employee) => $this->presenter->financeEmployee($employee))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function overview(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $metrics = $this->dashboardService->metrics();
        $latestRecords = FinanceEmployeePayrollRecord::query()
            ->with('employee')
            ->latest('period_start')
            ->limit(20)
            ->get();

        return $this->ok($this->presenter->payrollOverview($metrics['cards'] ?? [], $latestRecords));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate($this->employeeRules((int) $workspace->id));
        $employeeCode = trim((string) ($validated['employee_code'] ?? ''));

        $employee = FinanceEmployee::query()->create([
            'workspace_id' => $workspace->id,
            'employee_code' => $employeeCode !== '' ? $employeeCode : $this->nextEmployeeCode((int) $workspace->id),
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

        return $this->ok($this->presenter->financeEmployee($employee->fresh()), message: 'تمت إضافة موظف المالية بنجاح.', status: 201);
    }

    public function show(Request $request, FinanceEmployee $employee): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.view');
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $employee->load([
            'payrollRecords' => fn ($query) => $query->latest('period_start')->limit(48),
            'salaryAdvances' => fn ($query) => $query->with('repayments')->latest('issued_at'),
            'payrollAdjustments' => fn ($query) => $query->latest('effective_date')->limit(48),
        ]);

        return $this->ok($this->presenter->financeEmployee($employee, detailed: true));
    }

    public function update(Request $request, FinanceEmployee $employee): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $validated = $request->validate($this->employeeRules((int) $workspace->id, (int) $employee->id));
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

        return $this->ok($this->presenter->financeEmployee($employee->fresh()), message: 'تم تحديث موظف المالية.');
    }

    public function destroy(Request $request, FinanceEmployee $employee): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.manage');
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $employee->delete();

        return $this->ok(message: 'تم حذف سجل الموظف من المالية.');
    }

    public function storePayrollRecord(Request $request, FinanceEmployee $employee): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'payroll.manage');
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

        $record = FinanceEmployeePayrollRecord::query()->updateOrCreate(
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

        return $this->ok($this->presenter->payrollRecord($record->load('employee')), message: 'تم حفظ سجل الاستحقاق للفترة المحددة.');
    }

    /**
     * @return array<string, mixed>
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
