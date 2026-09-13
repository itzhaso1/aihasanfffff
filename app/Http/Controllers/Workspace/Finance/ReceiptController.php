<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceReceipt;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\PdfReceiptService;
use App\Services\Finance\ReceiptEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ReceiptController extends FinanceBaseController
{
    public function __construct(
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly PdfReceiptService $pdfReceiptService,
        private readonly ReceiptEmailService $receiptEmailService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'receipts.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());

        $receipts = FinanceReceipt::query()
            ->with(['customer', 'invoice', 'payment'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('receipt_number', 'like', '%'.$search.'%')
                        ->orWhere('reference', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('invoice', fn ($invoice) => $invoice->where('invoice_number', 'like', '%'.$search.'%'));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('invoice_id'), fn ($query) => $query->where('invoice_id', $request->integer('invoice_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('payment_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('payment_date', '<=', $request->string('to')->toString()))
            ->when($request->filled('method'), function ($query) use ($request): void {
                $query->whereHas('payment', fn ($payment) => $payment->where('method', $request->string('method')->toString()));
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.finance.receipts.index', [
            'receipts' => $receipts,
        ]);
    }

    public function show(Request $request, FinanceReceipt $receipt): View
    {
        $this->authorizeFinance($request, 'receipts.view');
        $this->assertSameWorkspace($receipt->workspace_id);

        return view('workspace.finance.receipts.show', [
            'receipt' => $receipt->load(['payment', 'invoice.customer', 'customer', 'deliveries.sender', 'creator']),
        ]);
    }

    public function downloadPdf(Request $request, FinanceReceipt $receipt)
    {
        $this->authorizeFinance($request, 'receipts.view');
        $this->assertSameWorkspace($receipt->workspace_id);

        try {
            return $this->pdfReceiptService->download($receipt);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function send(Request $request, FinanceReceipt $receipt): RedirectResponse
    {
        $this->authorizeFinance($request, 'receipts.send');
        $this->assertSameWorkspace($receipt->workspace_id);

        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:15000'],
            'attach_pdf' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'لا يوجد بريد إلكتروني للعميل.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
        ]);

        try {
            $delivery = $this->receiptEmailService->send(
                $receipt,
                [
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'subject' => $validated['subject'] ?? null,
                    'message' => $validated['message'] ?? null,
                    'attach_pdf' => $request->boolean('attach_pdf', true),
                ],
                (int) $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.receipts.show', $receipt)
            ->with('success', 'تم إرسال الإيصال إلى '.$delivery->recipient);
    }
}
