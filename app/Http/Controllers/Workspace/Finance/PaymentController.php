<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceInvoicePayment;
use App\Services\Finance\FinanceBootstrapService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends FinanceBaseController
{
    public function __construct(
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'payments.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());

        $payments = FinanceInvoicePayment::query()
            ->with(['invoice.customer', 'receipt', 'treasuryAccount'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('reference', 'like', '%'.$search.'%')
                        ->orWhereHas('invoice', fn ($invoice) => $invoice->where('invoice_number', 'like', '%'.$search.'%'))
                        ->orWhereHas('invoice.customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('customer_id'), function ($query) use ($request): void {
                $query->whereHas('invoice', fn ($invoice) => $invoice->where('customer_id', $request->integer('customer_id')));
            })
            ->when($request->filled('invoice_id'), fn ($query) => $query->where('invoice_id', $request->integer('invoice_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('payment_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('payment_date', '<=', $request->string('to')->toString()))
            ->when($request->filled('method'), fn ($query) => $query->where('method', $request->string('method')->toString()))
            ->when($request->filled('treasury_account_id'), fn ($query) => $query->where('treasury_account_id', $request->integer('treasury_account_id')))
            ->when($request->filled('reference'), fn ($query) => $query->where('reference', 'like', '%'.$request->string('reference')->toString().'%'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.finance.payments.index', [
            'payments' => $payments,
        ]);
    }
}
