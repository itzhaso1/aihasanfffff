<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Requests\Pos\UpdateCashierInvoiceRequest;
use App\Models\PosCashierInvoice;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvoiceController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly PosOrderService $posOrderService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $invoices = PosCashierInvoice::query()
            ->with(['table:id,name', 'closer:id,name', 'orders'])
            ->whereDate('closed_at', $date)
            ->latest('id')
            ->paginate(20);

        return $this->ok([
            'invoices' => $invoices->getCollection()->map(function (PosCashierInvoice $invoice) {
                $taxAmount = (float) $invoice->orders->sum('tax_amount');
                $paymentMethods = $invoice->orders
                    ->map(fn ($order) => data_get($order->metadata, 'payment_method'))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'currency' => $invoice->currency,
                    'subtotal' => (float) $invoice->subtotal,
                    'discount_amount' => (float) $invoice->discount_amount,
                    'tax_amount' => $taxAmount,
                    'total_amount' => (float) $invoice->total_amount,
                    'payment_method' => $paymentMethods->count() === 1
                        ? $paymentMethods->first()
                        : ($paymentMethods->isEmpty() ? null : $paymentMethods->implode(', ')),
                    'closed_at' => optional($invoice->closed_at)?->toIso8601String(),
                    'table' => $invoice->table ? ['id' => $invoice->table->id, 'name' => $invoice->table->name] : null,
                    'closer' => $invoice->closer ? ['id' => $invoice->closer->id, 'name' => $invoice->closer->name] : null,
                ];
            })->values(),
        ], meta: [
            'date' => $date,
            'current_page' => $invoices->currentPage(),
            'total' => $invoices->total(),
        ]);
    }

    public function show(Request $request, PosCashierInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);
        $invoice->load(['items', 'table', 'closer', 'orders']);

        $editable = $invoice->status === 'closed'
            && $invoice->orders->isNotEmpty()
            && ! $invoice->orders->contains(fn ($order) => $order->payment_status === 'paid');

        $taxAmount = (float) $invoice->orders->sum('tax_amount');
        $paymentMethods = $invoice->orders
            ->map(fn ($order) => data_get($order->metadata, 'payment_method'))
            ->filter()
            ->unique()
            ->values();

        return $this->ok([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'subtotal' => (float) $invoice->subtotal,
                'discount_amount' => (float) $invoice->discount_amount,
                'tax_amount' => $taxAmount,
                'total_amount' => (float) $invoice->total_amount,
                'payment_method' => $paymentMethods->count() === 1
                    ? $paymentMethods->first()
                    : ($paymentMethods->isEmpty() ? null : $paymentMethods->implode(', ')),
                'closed_at' => optional($invoice->closed_at)?->toIso8601String(),
                'notes' => data_get($invoice->metadata, 'notes'),
                'editable' => $editable,
                'store_name' => $workspace->name,
                'table' => $invoice->table ? ['id' => $invoice->table->id, 'name' => $invoice->table->name] : null,
                'closer' => $invoice->closer ? ['id' => $invoice->closer->id, 'name' => $invoice->closer->name] : null,
                'items' => $invoice->items->map(fn ($item) => [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'item_type' => $item->item_type,
                    'size_label' => $item->size_label,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'total_amount' => (float) $item->total_amount,
                ])->values(),
            ],
        ]);
    }

    public function update(UpdateCashierInvoiceRequest $request, PosCashierInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        try {
            $updated = $this->posOrderService->updateCashierInvoice($invoice, $request->validated(), $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'invoice_id' => $updated->id,
            'invoice_number' => $updated->invoice_number,
            'total_amount' => (float) $updated->total_amount,
        ], message: 'تم تعديل الفاتورة بنجاح.');
    }

    private function ensurePos(\App\Models\Workspace $workspace): void
    {
        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpResponseException(
                $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                    'pos_enabled' => false,
                    'plans_url' => url('/workspace/billing'),
                ])
            );
        }
    }
}
