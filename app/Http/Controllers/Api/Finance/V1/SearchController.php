<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Contract\Contract;
use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinancePurchaseOrder;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceReceipt;
use App\Models\Finance\FinanceSupplier;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $term = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 8);
        $like = '%'.$term.'%';

        return $this->ok([
            'customers' => Customer::query()
                ->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'name', 'email', 'phone'])
                ->map(fn (Customer $row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => $row->email ?: $row->phone,
                    'type' => 'customer',
                ])->all(),
            'invoices' => FinanceInvoice::query()
                ->where('type', 'sales')
                ->where(function ($query) use ($like): void {
                    $query->where('invoice_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'invoice_number', 'total', 'invoice_status', 'payment_status'])
                ->map(fn (FinanceInvoice $row): array => [
                    'id' => $row->id,
                    'title' => $row->invoice_number,
                    'subtitle' => trim(($row->invoice_status ?: '').' · '.$row->total),
                    'type' => 'invoice',
                ])->all(),
            'quotes' => FinanceQuote::query()
                ->where('quote_number', 'like', $like)
                ->limit($limit)
                ->get(['id', 'quote_number', 'status', 'outcome'])
                ->map(fn (FinanceQuote $row): array => [
                    'id' => $row->id,
                    'title' => $row->quote_number,
                    'subtitle' => $row->status.' / '.$row->outcome,
                    'type' => 'quote',
                ])->all(),
            'receipts' => FinanceReceipt::query()
                ->where('receipt_number', 'like', $like)
                ->limit($limit)
                ->get(['id', 'receipt_number', 'status'])
                ->map(fn (FinanceReceipt $row): array => [
                    'id' => $row->id,
                    'title' => $row->receipt_number,
                    'subtitle' => $row->status,
                    'type' => 'receipt',
                ])->all(),
            'payments' => FinanceInvoicePayment::query()
                ->where('reference', 'like', $like)
                ->limit($limit)
                ->get(['id', 'reference', 'amount', 'status'])
                ->map(fn (FinanceInvoicePayment $row): array => [
                    'id' => $row->id,
                    'title' => $row->reference ?: ('#'.$row->id),
                    'subtitle' => trim(($row->status ?: '').' · '.$row->amount),
                    'type' => 'payment',
                ])->all(),
            'expenses' => FinanceExpense::query()
                ->where(function ($query) use ($like): void {
                    $query->where('expense_number', 'like', $like)
                        ->orWhere('description', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'expense_number', 'description', 'total'])
                ->map(fn (FinanceExpense $row): array => [
                    'id' => $row->id,
                    'title' => $row->expense_number ?: ($row->description ?: '#'.$row->id),
                    'subtitle' => (string) $row->total,
                    'type' => 'expense',
                ])->all(),
            'suppliers' => FinanceSupplier::query()
                ->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('arabic_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('vat_number', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'name', 'vat_number'])
                ->map(fn (FinanceSupplier $row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => $row->vat_number,
                    'type' => 'supplier',
                ])->all(),
            'contracts' => Contract::query()
                ->where(function ($query) use ($like): void {
                    $query->where('contract_number', 'like', $like)
                        ->orWhere('title', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'contract_number', 'title', 'status'])
                ->map(fn (Contract $row): array => [
                    'id' => $row->id,
                    'title' => $row->contract_number ?: $row->title,
                    'subtitle' => $row->status,
                    'type' => 'contract',
                ])->all(),
            'purchases' => FinanceInvoice::query()
                ->where('type', 'purchase')
                ->where(function ($query) use ($like): void {
                    $query->where('invoice_number', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'invoice_number', 'total', 'invoice_status'])
                ->map(fn (FinanceInvoice $row): array => [
                    'id' => $row->id,
                    'title' => $row->invoice_number,
                    'subtitle' => (string) $row->total,
                    'type' => 'purchase',
                ])->all(),
            'products' => Product::query()
                ->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('barcode', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => $row->sku,
                    'type' => 'product',
                ])->all(),
            'projects' => FinanceProject::query()
                ->where('name', 'like', $like)
                ->limit($limit)
                ->get(['id', 'name', 'status'])
                ->map(fn (FinanceProject $row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => $row->status,
                    'type' => 'project',
                ])->all(),
            'purchase_orders' => FinancePurchaseOrder::query()
                ->where('po_number', 'like', $like)
                ->limit($limit)
                ->get(['id', 'po_number', 'status', 'total'])
                ->map(fn (FinancePurchaseOrder $row): array => [
                    'id' => $row->id,
                    'title' => $row->po_number,
                    'subtitle' => trim(($row->status ?: '').' · '.$row->total),
                    'type' => 'purchase_order',
                ])->all(),
            'leads' => CrmLead::query()
                ->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'name', 'company_name', 'status'])
                ->map(fn (CrmLead $row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => trim(($row->company_name ?: '').' · '.$row->status),
                    'type' => 'lead',
                ])->all(),
        ]);
    }
}
