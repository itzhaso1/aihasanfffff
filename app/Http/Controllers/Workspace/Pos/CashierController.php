<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Models\Order;
use App\Services\Pos\PosOrderStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CashierController extends PosBaseController
{
    public function __construct(
        private readonly PosOrderStatsService $posOrderStatsService,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $this->authorizePos($request, 'orders.manage');

        return redirect()
            ->route('workspace.pos.tables.index')
            ->with('info', 'تشغيل الكاشير يتم من تطبيق Flutter. هذه الواجهة للإدارة وQR Menu.');
    }

    public function storeOrder(Request $request): JsonResponse|RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    /**
     * Polling fallback for new QR menu orders when realtime is unavailable.
     */
    public function recentMenuOrders(Request $request): JsonResponse
    {
        $this->authorizePos($request, 'orders.manage');

        $afterId = max(0, (int) $request->query('after_id', 0));

        $orders = Order::query()
            ->with(['table:id,name', 'items:id,order_id,product_name,quantity,total_amount'])
            ->where('source', 'qr_menu')
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'table_name' => $order->table?->name,
                'dining_table_id' => $order->dining_table_id,
                'notes' => $order->notes,
                'total_amount' => (float) $order->total_amount,
                'currency' => $order->currency,
                'placed_at' => optional($order->placed_at)?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'total_amount' => (float) $item->total_amount,
                ])->values(),
            ])
            ->values();

        return response()->json([
            'orders' => $orders,
            'latest_id' => (int) ($orders->max('id') ?? $afterId),
        ]);
    }

    public function channelStats(Request $request): JsonResponse
    {
        $this->authorizePos($request, 'orders.manage');

        return response()->json([
            'stats' => $this->posOrderStatsService->channelCounts(),
        ]);
    }
}
