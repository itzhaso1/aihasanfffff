<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinancePurchaseOrder;
use App\Models\Finance\FinanceSupplier;
use App\Models\Product;
use App\Services\Finance\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PurchaseOrderController extends FinanceBaseController
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        return view('workspace.finance.purchase-orders.index', [
            'orders' => FinancePurchaseOrder::query()->with('supplier')->latest('id')->paginate(20),
            'suppliers' => FinanceSupplier::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->orderBy('name')->limit(100)->get(['id', 'name', 'cost_price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items_json' => ['required', 'string'],
        ]);
        $validated['items'] = $this->parseJsonField($request, 'items_json');

        try {
            $this->purchaseOrderService->create($this->currentWorkspace(), $validated, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إنشاء أمر الشراء.');
    }

    public function submit(Request $request, FinancePurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $this->assertSameWorkspace($purchaseOrder->workspace_id);

        try {
            $this->purchaseOrderService->submit($purchaseOrder);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إرسال أمر الشراء.');
    }

    public function receive(Request $request, FinancePurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $this->assertSameWorkspace($purchaseOrder->workspace_id);
        $receipts = $this->parseJsonField($request, 'receipts_json');

        try {
            $this->purchaseOrderService->receive($purchaseOrder, $receipts, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم استلام الكميات.');
    }

    public function bill(Request $request, FinancePurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.create');
        $this->assertSameWorkspace($purchaseOrder->workspace_id);

        try {
            $invoice = $this->purchaseOrderService->convertToBill(
                $this->currentWorkspace(),
                $purchaseOrder,
                (int) $request->user()->id
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.invoices.show', $invoice)
            ->with('success', 'تم إنشاء فاتورة المورد من أمر الشراء.');
    }
}
