<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceExpense;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly ExpenseService $expenseService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'expenses.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,approved,paid,cancelled'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = FinanceExpense::query()
            ->with(['supplier', 'category', 'treasuryAccount'])
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('expense_number', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinanceExpense $expense) => $this->presenter->expense($expense))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinanceExpense $expense): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'expenses.view');
        $expense->load(['supplier', 'category', 'treasuryAccount']);

        return $this->ok($this->presenter->expense($expense));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'expenses.create');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        $payload = $this->validatedExpense($request, (int) $workspace->id, creating: true);
        if ($request->hasFile('attachment_file')) {
            $payload['attachment_file'] = $request->file('attachment_file');
        }

        $expense = $this->runFinanceDomain(
            fn () => $this->expenseService->create($workspace, $payload, (int) $request->user()?->id)
        );

        return $this->ok(
            $this->presenter->expense($expense->load(['supplier', 'category', 'treasuryAccount'])),
            message: 'تم إنشاء المصروف.',
            status: 201,
        );
    }

    public function update(Request $request, FinanceExpense $expense): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'expenses.edit');
        $payload = $this->validatedExpense($request, (int) $workspace->id, creating: false);
        if ($request->hasFile('attachment_file')) {
            $payload['attachment_file'] = $request->file('attachment_file');
        }

        $updated = $this->runFinanceDomain(
            fn () => $this->expenseService->updateDraft($expense, $payload, (int) $request->user()?->id)
        );

        return $this->ok($this->presenter->expense($updated->load(['supplier', 'category', 'treasuryAccount'])), message: 'تم تحديث مسودة المصروف.');
    }

    public function destroy(Request $request, FinanceExpense $expense): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'expenses.edit');
        $this->runFinanceDomain(fn () => $this->expenseService->delete($expense, (int) $request->user()?->id));

        return $this->ok(message: 'تم إلغاء المصروف.');
    }

    public function attachment(Request $request, FinanceExpense $expense): mixed
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'expenses.view');
        abort_unless(is_string($expense->attachment_path) && $expense->attachment_path !== '', 404);
        abort_unless(Storage::disk('public')->exists($expense->attachment_path), 404);

        return Storage::disk('public')->download(
            $expense->attachment_path,
            basename($expense->attachment_path)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExpense(Request $request, int $workspaceId, bool $creating): array
    {
        $rules = [
            'expense_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_profile_type' => ['nullable', 'in:standard,zero_rated,exempt,out_of_scope'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'in:cash,bank_transfer,card,other,credit'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurring_frequency' => ['nullable', 'in:weekly,monthly,quarterly,yearly'],
            'next_due_date' => ['nullable', 'date'],
            'attachment_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:4096'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_suppliers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_expense_categories', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'treasury_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_treasury_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
        ];

        if ($creating) {
            $rules['status'] = ['nullable', 'in:draft,approved,paid,cancelled'];
        }

        return $request->validate($rules);
    }
}
