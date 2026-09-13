<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinancePayrollRun;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Finance\CustomerBalanceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModulePageController extends FinanceBaseController
{
    public function customers(Request $request, CustomerBalanceService $customerBalanceService): View
    {
        $this->authorizeFinance($request, 'finance.view');
        $workspace = $this->currentWorkspace();

        $customers = Customer::query()
            ->withCount(['orders', 'conversations'])
            ->withSum('orders as orders_total_amount', 'total_amount')
            ->latest('id')
            ->paginate(15);

        $customerIds = $customers->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $invoiceCountByCustomer = FinanceInvoice::query()
            ->where('type', 'sales')
            ->whereNotNull('customer_id')
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) as invoices_count')
            ->pluck('invoices_count', 'customer_id')
            ->all();

        return view('workspace.finance.modules.customers', [
            'customers' => $customers,
            'invoiceCountByCustomer' => $invoiceCountByCustomer,
            'outstandingByCustomer' => $customerBalanceService->outstandingByCustomerIds(
                (int) $workspace->id,
                $customerIds
            ),
        ]);
    }

    public function products(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        $products = Product::query()
            ->with('category')
            ->withCount('variants')
            ->latest('id')
            ->paginate(20);

        $salesByProduct = FinanceInvoiceItem::query()
            ->selectRaw('product_id, COALESCE(SUM(quantity),0) as sold_qty, COALESCE(SUM(total),0) as sold_total')
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->pluck('sold_total', 'product_id')
            ->all();

        return view('workspace.finance.modules.products', [
            'products' => $products,
            'salesByProduct' => $salesByProduct,
        ]);
    }

    public function inventory(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        $movements = InventoryMovement::query()
            ->with(['product', 'variant', 'user'])
            ->latest('id')
            ->paginate(20);

        return view('workspace.finance.modules.inventory', [
            'movements' => $movements,
        ]);
    }

    public function payroll(Request $request): View
    {
        $this->authorizeFinance($request, 'payroll.view');

        $employees = FinanceEmployee::query()
            ->withCount('payrollRecords')
            ->latest('id')
            ->paginate(12);

        $runs = FinancePayrollRun::query()->latest('period_month')->limit(12)->get();
        $latestRecords = FinanceEmployeePayrollRecord::query()
            ->with('employee')
            ->latest('period_start')
            ->limit(20)
            ->get();

        return view('workspace.finance.modules.payroll', [
            'employees' => $employees,
            'runs' => $runs,
            'latestRecords' => $latestRecords,
        ]);
    }

    public function vat(Request $request): View
    {
        $this->authorizeFinance($request, 'accounting.view');

        $rates = FinanceTaxRate::query()->orderByDesc('is_default')->get();
        $output = (float) FinanceInvoice::query()
            ->where('type', 'sales')
            ->sum('tax_amount');
        $inputFromPurchases = (float) FinanceInvoice::query()
            ->where('type', 'purchase')
            ->sum('tax_amount');
        $inputFromExpenses = (float) FinanceExpense::query()->sum('tax_amount');
        $input = $inputFromPurchases + $inputFromExpenses;

        return view('workspace.finance.modules.vat', [
            'rates' => $rates,
            'vat' => [
                'output' => round($output, 2),
                'input' => round($input, 2),
                'net' => round($output - $input, 2),
            ],
        ]);
    }

    public function banks(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        $treasuryAccounts = FinanceTreasuryAccount::query()
            ->with('linkedAccount')
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(20);

        return view('workspace.finance.modules.banks', [
            'treasuryAccounts' => $treasuryAccounts,
        ]);
    }

    public function placeholder(Request $request, string $key): View
    {
        $this->authorizeFinance($request, 'finance.view');

        $titles = [
            'sales' => 'المبيعات',
            'purchases' => 'المشتريات',
            'cashbox' => 'الصندوق',
            'settings-accounting' => 'إعدادات المحاسبة',
        ];

        return view('workspace.finance.modules.placeholder', [
            'title' => $titles[$key] ?? 'وحدة مالية',
            'moduleKey' => $key,
        ]);
    }

    public function sales(Request $request): View
    {
        return $this->placeholder($request, 'sales');
    }

    public function purchases(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        $filters = [
            'search' => trim((string) $request->string('search')),
            'invoice_status' => trim((string) $request->string('invoice_status')),
            'payment_status' => trim((string) $request->string('payment_status')),
            'status' => trim((string) $request->string('status')),
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'from' => trim((string) $request->string('from')),
            'to' => trim((string) $request->string('to')),
        ];

        if ($filters['status'] !== '' && $filters['invoice_status'] === '' && $filters['payment_status'] === '') {
            if (in_array($filters['status'], ['draft', 'cancelled'], true)) {
                $filters['invoice_status'] = $filters['status'];
            } elseif ($filters['status'] === 'sent') {
                $filters['invoice_status'] = 'issued';
            } elseif (in_array($filters['status'], ['unpaid', 'partial', 'paid', 'overdue'], true)) {
                $filters['payment_status'] = $filters['status'];
            }
        }

        $query = FinanceInvoice::query()
            ->where('type', 'purchase')
            ->with('supplier')
            ->when($filters['search'] !== '', function ($builder) use ($filters): void {
                $builder->where(function ($inner) use ($filters): void {
                    $inner->where('invoice_number', 'like', '%'.$filters['search'].'%')
                        ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%'.$filters['search'].'%'));
                });
            })
            ->when($filters['invoice_status'] !== '', fn ($builder) => $builder->whereInvoiceStatus($filters['invoice_status']))
            ->when($filters['payment_status'] !== '', fn ($builder) => $builder->wherePaymentStatus($filters['payment_status']))
            ->when($filters['supplier_id'], fn ($builder) => $builder->where('supplier_id', $filters['supplier_id']))
            ->when($filters['from'] !== '', fn ($builder) => $builder->whereDate('issue_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($builder) => $builder->whereDate('issue_date', '<=', $filters['to']));

        $invoices = (clone $query)
            ->latest('issue_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $summary = [
            'invoice_count' => (clone $query)->count(),
            'total_purchases' => round((float) (clone $query)->sum('total'), 2),
            'total_due' => round((float) (clone $query)->sum('amount_due'), 2),
            'total_paid' => round((float) (clone $query)->sum('amount_paid'), 2),
            'overdue_count' => (clone $query)
                ->whereIssued()
                ->wherePaymentStatus('overdue')
                ->count(),
            'unpaid_count' => (clone $query)
                ->whereIssued()
                ->where(function ($builder): void {
                    $builder->where(function ($stateQuery): void {
                        $stateQuery->wherePaymentStatus('unpaid');
                    })->orWhere(function ($stateQuery): void {
                        $stateQuery->wherePaymentStatus('partial');
                    })->orWhere(function ($stateQuery): void {
                        $stateQuery->wherePaymentStatus('overdue');
                    });
                })
                ->count(),
        ];

        return view('workspace.finance.modules.purchases', [
            'invoices' => $invoices,
            'summary' => $summary,
            'suppliers' => FinanceSupplier::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function cashbox(Request $request): View
    {
        return $this->placeholder($request, 'cashbox');
    }
}
