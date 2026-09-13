<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['customer', 'items', 'table', 'tableSession', 'financeInvoice', 'posCashierInvoice'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('order_number', 'like', '%'.$search.'%');
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->string('payment_status')->toString()))
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create($request->validated(), $request->user());

        return response()->json(['data' => $order], 201);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json(['data' => $order->load(['customer', 'items', 'payments', 'table', 'tableSession', 'financeInvoice', 'posCashierInvoice'])]);
    }

    public function update(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);
        $validated = $request->validated();

        if (($validated['status'] ?? null) === 'cancelled') {
            $order = $this->orderService->cancel($order, $request->user());
        } else {
            $order->update($validated);
            $order = $order->refresh();
        }

        return response()->json(['data' => $order]);
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->authorize('delete', $order);
        $this->orderService->cancel($order);
        $order->delete();

        return response()->json(status: 204);
    }
}
