<?php

namespace App\Services\Finance;

use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSupplier;
use App\Models\Product;
use Illuminate\Support\Collection;

class FinanceSearchService
{
    /**
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    public function search(int $workspaceId, string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.$term.'%';

        return [
            'customers' => Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
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
                    'url' => route('workspace.finance.customers.index', ['search' => $row->name]),
                ]),
            'invoices' => FinanceInvoice::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($like): void {
                    $query->where('invoice_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'invoice_number', 'type', 'total'])
                ->map(fn (FinanceInvoice $row): array => [
                    'id' => $row->id,
                    'title' => $row->invoice_number,
                    'subtitle' => $row->type.' · '.$row->total,
                    'url' => route('workspace.finance.invoices.show', $row),
                ]),
            'expenses' => FinanceExpense::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($like): void {
                    $query->where('expense_number', 'like', $like)
                        ->orWhere('description', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'expense_number', 'description', 'total'])
                ->map(fn (FinanceExpense $row): array => [
                    'id' => $row->id,
                    'title' => $row->expense_number,
                    'subtitle' => $row->description,
                    'url' => route('workspace.finance.expenses.index'),
                ]),
            'products' => Product::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
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
                    'url' => route('workspace.finance.products.index'),
                ]),
            'suppliers' => FinanceSupplier::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('name', 'like', $like)
                ->limit($limit)
                ->get(['id', 'name', 'email'])
                ->map(fn (FinanceSupplier $row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => $row->email,
                    'url' => route('workspace.finance.suppliers.index'),
                ]),
            'leads' => CrmLead::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->limit($limit)
                ->get(['id', 'name', 'email'])
                ->map(fn ($row): array => [
                    'id' => $row->id,
                    'title' => $row->name,
                    'subtitle' => $row->email,
                    'url' => route('workspace.finance.leads.index'),
                ]),
        ];
    }
}
