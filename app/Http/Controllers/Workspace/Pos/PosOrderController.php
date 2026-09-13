<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosOrderController extends PosBaseController
{

    public function qrOrders(Request $request): View
    {
        $this->authorizePos($request, 'orders.manage');

        $orders = Order::query()
            ->with(['table:id,name', 'tableSession:id,dining_table_id,opened_at,status', 'items'])
            ->where('source', 'qr_menu')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('workspace.pos.orders.running', [
            'orders' => $orders,
            'posStatuses' => $this->posStatusLabels(),
            'pageTitle' => 'طلبات QR Menu',
        ]);
    }

    public function running(Request $request): View
    {
        $this->authorizePos($request, 'orders.manage');

        $orders = Order::query()
            ->with(['table:id,name', 'tableSession:id,dining_table_id,opened_at,status', 'items'])
            ->whereIn('source', ['pos', 'qr_menu'])
            ->where(function ($query): void {
                $query->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready', 'delivered'])
                    ->orWhere(function ($inner): void {
                        $inner->whereNull('table_session_id')
                            ->where('pos_status', 'completed');
                    });
            })
            ->whereNull('pos_cashier_invoice_id')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.pos.orders.running', [
            'orders' => $orders,
            'posStatuses' => $this->posStatusLabels(),
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function updateItems(Request $request, Order $order): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function createInvoice(Request $request, Order $order): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function createPaymentLink(Request $request, Order $order): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function printOrder(Request $request, Order $order): View
    {
        $this->authorizePos($request, 'orders.manage');
        $this->authorize('view', $order);
        $this->ensurePosOrder($order);

        return view('workspace.pos.orders.print-order', [
            'order' => $order->load(['items', 'table', 'tableSession', 'customer']),
        ]);
    }

    private function ensurePosOrder(Order $order): void
    {
        abort_unless(in_array($order->source, ['pos', 'qr_menu'], true), 404);
    }

    /**
     * @return array<string,string>
     */
    private function posStatusLabels(): array
    {
        return [
            'new' => 'جديد',
            'accepted' => 'مقبول',
            'preparing' => 'قيد التحضير',
            'ready' => 'جاهز',
            'delivered' => 'تم التسليم',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
        ];
    }
}
