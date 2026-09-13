<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Finance\FinanceBillingSchedule;
use App\Services\Contracts\ContractPdfService;
use App\Services\Contracts\ContractService;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ContractClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly ContractService $contractService,
        private readonly ContractPdfService $contractPdfService,
        private readonly BillingScheduleService $billingScheduleService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,open,closed,cancelled'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Contract::query()
            ->with(['customer', 'billingSchedules', 'items', 'attachments'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('contract_number', 'like', '%'.$search.'%')
                        ->orWhere('title', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (Contract $contract) => $this->presenter->contract($contract))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.view');
        $contract->load(['customer', 'billingSchedules', 'invoices', 'items', 'attachments']);

        $payload = $this->presenter->contract($contract);
        $payload['billing_summary'] = [
            'invoiced_total' => $this->presenter->money($contract->invoices->sum('total')),
            'paid_total' => $this->presenter->money($contract->invoices->sum('amount_paid')),
            'outstanding' => $this->presenter->money($contract->invoices->sum('amount_due')),
        ];
        $payload['generated_invoices'] = $contract->invoices
            ->map(fn ($invoice) => $this->presenter->invoiceSummary($invoice))
            ->values()
            ->all();

        return $this->ok($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        $payload = $request->validate($this->rules((int) $workspace->id));
        $payload['status'] = 'draft';

        $contract = $this->contractService->create(
            $workspace,
            $payload,
            (int) $request->user()?->id,
            $request->file('attachments', []) ?: []
        );

        return $this->ok(
            $this->presenter->contract($contract->load(['customer', 'billingSchedules', 'attachments'])),
            message: 'تم إنشاء العقد.',
            status: 201,
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        $payload = $request->validate($this->rules((int) $workspace->id, (int) $contract->id));
        $updated = $this->runFinanceDomain(
            fn () => $this->contractService->update($contract, $payload, $request->file('attachments', []) ?: [])
        );

        return $this->ok($this->presenter->contract($updated->load(['customer', 'billingSchedules', 'attachments'])), message: 'تم تحديث العقد.');
    }

    public function activate(Request $request, Contract $contract): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->clientActor($request, $workspace, 'contracts.manage');
        $activated = $this->runFinanceDomain(fn () => $this->contractService->activate($contract, (int) $user->id));

        return $this->ok($this->presenter->contract($activated->load(['customer', 'billingSchedules', 'attachments'])), message: 'تم تفعيل العقد.');
    }

    public function close(Request $request, Contract $contract): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        $closed = $this->runFinanceDomain(fn () => $this->contractService->close($contract));

        return $this->ok($this->presenter->contract($closed->load(['customer', 'billingSchedules', 'attachments'])), message: 'تم إغلاق العقد.');
    }

    public function cancel(Request $request, Contract $contract): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        $cancelled = $this->runFinanceDomain(fn () => $this->contractService->cancel($contract));

        return $this->ok($this->presenter->contract($cancelled->load(['customer', 'billingSchedules', 'attachments'])), message: 'تم إلغاء العقد.');
    }

    public function pdf(Request $request, Contract $contract): mixed
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.view');

        return $this->contractPdfService->download($contract);
    }

    public function storeAttachment(Request $request, Contract $contract): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'contracts.manage');
        if (in_array((string) $contract->status, ['closed', 'cancelled'], true)) {
            return $this->fail('لا يمكن إرفاق ملفات بعقد مغلق أو ملغي.', ApiErrorCode::ValidationFailed, 422);
        }
        $request->validate([
            'attachments' => ['required', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $updated = $this->runFinanceDomain(
            fn () => $this->contractService->storeAttachments($contract, $request->file('attachments', []) ?: [])
        );

        return $this->ok(
            $this->presenter->contract($updated->load(['customer', 'billingSchedules', 'attachments'])),
            message: 'تم رفع المرفق.',
        );
    }

    public function downloadAttachment(Request $request, Contract $contract, ContractAttachment $attachment): mixed
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'contracts.view');
        abort_unless((int) $attachment->contract_id === (int) $contract->id, 404);

        return Storage::disk('public')->download(
            $attachment->file_path,
            $attachment->file_name ?: ('contract-attachment-'.$attachment->id)
        );
    }

    public function destroyAttachment(Request $request, Contract $contract, ContractAttachment $attachment): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'contracts.manage');
        abort_unless((int) $attachment->contract_id === (int) $contract->id, 404);
        $this->runFinanceDomain(fn () => $this->contractService->deleteAttachment($attachment));

        return $this->ok(
            $this->presenter->contract($contract->fresh()->load(['customer', 'billingSchedules', 'attachments', 'items'])),
            message: 'تم حذف المرفق.',
        );
    }

    public function storeSchedule(Request $request, Contract $contract): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly,installment'],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:24'],
            'total_occurrences' => ['required', 'integer', 'min:1', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'auto_issue' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,active'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $schedule = $this->runFinanceDomain(
            fn () => $this->billingScheduleService->createFromContract($contract, $validated, (int) $request->user()?->id)
        );

        return $this->ok(
            $this->presenter->contract($contract->fresh()->load(['customer', 'billingSchedules'])),
            message: 'تم إنشاء جدول الفوترة '.$schedule->title.'.',
        );
    }

    public function activateSchedule(Request $request, Contract $contract, FinanceBillingSchedule $schedule): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);
        $this->runFinanceDomain(fn () => $this->billingScheduleService->activate($schedule));

        return $this->ok(
            $this->presenter->contract($contract->fresh()->load(['customer', 'billingSchedules'])),
            message: 'تم تفعيل جدول الفوترة.',
        );
    }

    public function pauseSchedule(Request $request, Contract $contract, FinanceBillingSchedule $schedule): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);
        $this->runFinanceDomain(fn () => $this->billingScheduleService->pause($schedule));

        return $this->ok(
            $this->presenter->contract($contract->fresh()->load(['customer', 'billingSchedules'])),
            message: 'تم إيقاف جدول الفوترة.',
        );
    }

    public function cancelSchedule(Request $request, Contract $contract, FinanceBillingSchedule $schedule): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'contracts.manage');
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);
        $this->billingScheduleService->cancel($schedule);

        return $this->ok(
            $this->presenter->contract($contract->fresh()->load(['customer', 'billingSchedules'])),
            message: 'تم إلغاء جدول الفوترة.',
        );
    }

    public function generateInvoice(Request $request, Contract $contract, FinanceBillingSchedule $schedule): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.create');
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);

        $invoice = $this->runFinanceDomain(fn () => $this->billingScheduleService->generateOne($schedule));
        if (! $invoice) {
            return $this->fail(
                'لا توجد دورة مستحقة لتوليد فاتورة حالياً.',
                ApiErrorCode::ValidationFailed,
                422,
            );
        }

        return $this->ok([
            'invoice' => $this->presenter->invoiceSummary($invoice->load('customer')),
            'contract' => $this->presenter->contract($contract->fresh()->load(['customer', 'billingSchedules'])),
        ], message: 'تم توليد الفاتورة '.$invoice->invoice_number.' كمسودة.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(int $workspaceId, ?int $ignoreContractId = null): array
    {
        return [
            'contract_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('contracts', 'contract_number')
                    ->where(fn ($query) => $query->where('workspace_id', $workspaceId))
                    ->ignore($ignoreContractId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'terms' => ['nullable', 'string', 'max:40000'],
            'notes' => ['nullable', 'string', 'max:15000'],
        ];
    }
}
