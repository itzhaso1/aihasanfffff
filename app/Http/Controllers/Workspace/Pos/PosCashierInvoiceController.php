<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Http\Requests\Pos\UpdateCashierInvoiceRequest;
use App\Models\PosCashierInvoice;
use App\Services\Pos\PosOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PosCashierInvoiceController extends PosBaseController
{
    public function __construct(
        private readonly PosOrderService $posOrderService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizePos($request, 'orders.manage');

        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $invoices = PosCashierInvoice::query()
            ->with(['table:id,name', 'closer:id,name'])
            ->whereDate('closed_at', $date)
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.pos.invoices.index', [
            'invoices' => $invoices,
            'date' => $date,
        ]);
    }

    public function show(Request $request, PosCashierInvoice $invoice): View
    {
        $this->authorizePos($request, 'orders.manage');
        $this->authorize('view', $invoice);

        return view('workspace.pos.invoices.show', [
            'invoice' => $invoice->load(['items', 'table', 'session', 'closer', 'orders']),
            'canEdit' => $this->invoiceIsEditable($invoice),
        ]);
    }

    public function edit(Request $request, PosCashierInvoice $invoice): View|RedirectResponse
    {
        $this->authorizePos($request, 'orders.manage');
        $this->authorize('update', $invoice);

        $invoice->load(['items', 'table', 'session', 'closer', 'orders']);

        if (! $this->invoiceIsEditable($invoice)) {
            return redirect()
                ->route('workspace.pos.invoices.show', $invoice)
                ->with('error', 'لا يمكن تعديل هذه الفاتورة. الطلبات المدفوعة تُعدَّل عبر المرتجعات.');
        }

        return view('workspace.pos.invoices.edit', [
            'invoice' => $invoice,
        ]);
    }

    public function update(UpdateCashierInvoiceRequest $request, PosCashierInvoice $invoice): RedirectResponse
    {
        $this->authorizePos($request, 'orders.manage');
        $this->authorize('update', $invoice);

        try {
            $updated = $this->posOrderService->updateCashierInvoice(
                $invoice,
                $request->validated(),
                $request->user()
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.pos.invoices.show', $updated)
            ->with('success', 'تم تعديل الفاتورة بنجاح.');
    }

    public function print(Request $request, PosCashierInvoice $invoice): View
    {
        $this->authorizePos($request, 'orders.manage');
        $this->authorize('view', $invoice);

        return view('workspace.pos.invoices.print', [
            'invoice' => $invoice->load(['items', 'table', 'session', 'closer']),
        ]);
    }

    private function invoiceIsEditable(PosCashierInvoice $invoice): bool
    {
        if ($invoice->status !== 'closed') {
            return false;
        }

        $orders = $invoice->relationLoaded('orders')
            ? $invoice->orders
            : $invoice->orders()->get(['id', 'payment_status']);

        if ($orders->isEmpty()) {
            return false;
        }

        return ! $orders->contains(fn ($order): bool => $order->payment_status === 'paid');
    }
}
