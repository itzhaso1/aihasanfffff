<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Order\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(private readonly OrderService $orderService) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);

        $orders = Order::query()
            ->with('customer')
            ->withCount('items')
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('order_number', 'like', '%'.$search.'%');
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('workspace.orders.index', compact('orders'));
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        return view('workspace.orders.create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'sku', 'price', 'sale_price', 'stock']),
            'variants' => ProductVariant::query()->orderBy('name')->get(['id', 'product_id', 'name', 'sku', 'price', 'sale_price', 'stock']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $payload = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'currency' => ['required', 'string', 'size:3'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,confirmed,cancelled,completed'],
            'items_json' => ['required', 'string'],
        ]);

        $items = json_decode($payload['items_json'], true);
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'items_json' => 'صيغة العناصر غير صحيحة، يجب أن تكون JSON Array.',
            ]);
        }

        $order = $this->orderService->create([
            'customer_id' => $payload['customer_id'] ?? null,
            'currency' => $payload['currency'],
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'shipping_amount' => $payload['shipping_amount'] ?? 0,
            'notes' => $payload['notes'] ?? null,
            'status' => $payload['status'] ?? 'confirmed',
            'items' => $items,
        ], $request->user());

        return redirect()
            ->route('workspace.orders.edit', $order)
            ->with('success', 'تم إنشاء الطلب بنجاح.');
    }

    public function edit(Order $order): View
    {
        $this->authorize('update', $order);

        return view('workspace.orders.edit', [
            'order' => $order->load(['customer', 'items.product', 'items.variant', 'table', 'tableSession', 'financeInvoice', 'posCashierInvoice']),
        ]);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        $payload = $request->validated();
        if (($payload['status'] ?? null) === 'cancelled') {
            $this->orderService->cancel($order->load('items'), $request->user());
        } else {
            $order->update($payload);
        }

        return redirect()->route('workspace.orders.edit', $order)->with('success', 'تم تحديث حالة الطلب.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->authorize('delete', $order);

        $order->delete();

        return redirect()->route('workspace.orders.index')->with('success', 'تم حذف الطلب.');
    }
}
