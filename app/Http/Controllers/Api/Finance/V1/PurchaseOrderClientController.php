<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinancePurchaseOrder;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\PurchaseOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = FinancePurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('po_number', 'like', '%'.$search.'%')
                        ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinancePurchaseOrder $order) => $this->presenter->purchaseOrder($order))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.view');
        $purchaseOrder->load(['supplier', 'items']);

        return $this->ok($this->presenter->purchaseOrder($purchaseOrder));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.manage');
        $validated = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('finance_suppliers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items_json' => ['nullable', 'string'],
        ]);
        $validated['items'] = $this->documentItemsFromRequest($request);

        $order = $this->runFinanceDomain(
            fn () => $this->purchaseOrderService->create($workspace, $validated, (int) $request->user()?->id)
        );

        return $this->ok(
            $this->presenter->purchaseOrder($order->load(['supplier', 'items'])),
            message: 'تم إنشاء أمر الشراء.',
            status: 201,
        );
    }

    public function submit(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.manage');
        $updated = $this->runFinanceDomain(fn () => $this->purchaseOrderService->submit($purchaseOrder));

        return $this->ok($this->presenter->purchaseOrder($updated->load(['supplier', 'items'])), message: 'تم إرسال أمر الشراء.');
    }

    public function receive(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.manage');
        $receipts = $request->input('receipts');
        if ($receipts === null && is_string($request->input('receipts_json'))) {
            $receipts = json_decode((string) $request->input('receipts_json'), true);
        }
        if (! is_array($receipts) || $receipts === []) {
            throw ValidationException::withMessages(['receipts' => 'يرجى تحديد كميات الاستلام.']);
        }
        $updated = $this->runFinanceDomain(
            fn () => $this->purchaseOrderService->receive($purchaseOrder, $receipts, (int) $request->user()?->id)
        );

        return $this->ok($this->presenter->purchaseOrder($updated->load(['supplier', 'items'])), message: 'تم استلام الكميات.');
    }

    public function bill(Request $request, FinancePurchaseOrder $purchaseOrder): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.create');
        $invoice = $this->runFinanceDomain(
            fn () => $this->purchaseOrderService->convertToBill($workspace, $purchaseOrder, (int) $request->user()?->id)
        );

        return $this->ok([
            'purchase_order' => $this->presenter->purchaseOrder($purchaseOrder->fresh()->load(['supplier', 'items'])),
            'invoice' => $this->presenter->invoiceSummary($invoice->load('supplier')),
        ], message: 'تم تحويل أمر الشراء إلى فاتورة شراء.');
    }
}
