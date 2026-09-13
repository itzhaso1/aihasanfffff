<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\PdfCreditNoteService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CreditNoteClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly CreditNoteService $creditNoteService,
        private readonly PdfCreditNoteService $pdfCreditNoteService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'type' => ['nullable', 'in:credit,debit'],
            'status' => ['nullable', 'in:draft,issued,cancelled'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = FinanceCreditNote::query()
            ->with(['customer', 'invoice'])
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('note_number', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinanceCreditNote $note) => $this->presenter->noteSummary($note))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinanceCreditNote $creditNote): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.view');
        $creditNote->load(['customer', 'invoice', 'items']);

        return $this->ok($this->presenter->noteDetail($creditNote));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.credit');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'invoice_id' => [
                'required',
                'integer',
                Rule::exists('finance_invoices', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'type' => ['required', 'in:credit,debit'],
            'reason' => ['required', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,issued'],
        ]);

        $invoice = FinanceInvoice::query()->whereKey($validated['invoice_id'])->firstOrFail();
        $items = $this->documentItemsFromRequest($request);

        $note = $this->runFinanceDomain(fn () => $this->creditNoteService->create(
            $workspace,
            $invoice,
            [
                ...Arr::except($validated, ['invoice_id']),
                'items' => $items,
                'tax_profile_type' => $invoice->tax_profile_type,
                'tax_rate' => $invoice->tax_rate,
            ],
            (int) $request->user()?->id
        ));

        return $this->ok(
            $this->presenter->noteDetail($note->load(['customer', 'invoice', 'items'])),
            message: 'تم إنشاء الإشعار.',
            status: 201,
        );
    }

    public function issue(Request $request, FinanceCreditNote $creditNote): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->clientActor($request, $workspace, 'invoices.credit');
        $issued = $this->runFinanceDomain(fn () => $this->creditNoteService->issue($creditNote, (int) $user->id));

        return $this->ok($this->presenter->noteDetail($issued->load(['customer', 'invoice', 'items'])), message: 'تم إصدار الإشعار.');
    }

    public function cancel(Request $request, FinanceCreditNote $creditNote): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->clientActor($request, $workspace, 'invoices.cancel');
        $cancelled = $this->runFinanceDomain(fn () => $this->creditNoteService->cancel($creditNote, (int) $user->id));

        return $this->ok($this->presenter->noteDetail($cancelled->load(['customer', 'invoice', 'items'])), message: 'تم إلغاء الإشعار.');
    }

    public function pdf(Request $request, FinanceCreditNote $creditNote): mixed
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.view');

        return $this->runFinanceDomain(fn () => $this->pdfCreditNoteService->download($creditNote));
    }
}
