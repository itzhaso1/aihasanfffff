<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosCashierInvoice;
use App\Models\PosCashierInvoiceItem;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\TableSession;
use App\Services\Pos\PosOrderStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PosReportController extends PosBaseController
{
    public function __construct(
        private readonly PosOrderStatsService $posOrderStatsService,
    ) {}

    public function daily(Request $request): View
    {
        $this->authorizePos($request, 'reports.view');
        $window = $this->posOrderStatsService->resolveReportWindow(
            $request->input('date'),
            $request->input('from'),
            $request->input('to'),
        );
        $date = $window['date'];
        $from = $window['from'];
        $to = $window['to'];

        $cashierInvoices = PosCashierInvoice::query()
            ->with(['table:id,name', 'closer:id,name', 'items'])
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to)
            ->latest('id')
            ->get();

        $orders = Order::query()
            ->with(['customer:id,name,phone', 'table:id,name', 'items'])
            ->whereIn('source', ['pos', 'qr_menu'])
            ->whereDate('placed_at', '>=', $from)
            ->whereDate('placed_at', '<=', $to)
            ->latest('id')
            ->get();

        $closedOrderIds = $orders->whereNotNull('pos_cashier_invoice_id')->pluck('id')->all();
        $lineBaseQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $closedOrderIds === [] ? [0] : $closedOrderIds);

        $quantityByType = (clone $lineBaseQuery)
            ->selectRaw("COALESCE(order_items.item_type, 'عام') as item_type, SUM(order_items.quantity) as quantity, SUM(order_items.total_amount) as sales")
            ->groupBy(DB::raw("COALESCE(order_items.item_type, 'عام')"))
            ->orderByDesc('quantity')
            ->get();

        $topItems = (clone $lineBaseQuery)
            ->selectRaw('order_items.product_name, SUM(order_items.quantity) as quantity, SUM(order_items.total_amount) as sales')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity')
            ->limit(20)
            ->get();

        $customerSummary = $this->buildCustomerSummary($orders);
        $salesByHour = $this->buildSalesByHour($orders->whereNotNull('pos_cashier_invoice_id'));
        $orderChannelStats = $this->posOrderStatsService->channelCounts(
            \Illuminate\Support\Carbon::parse($from)->startOfDay(),
            \Illuminate\Support\Carbon::parse($to)->startOfDay(),
        );
        $cashSalesTotal = (float) $cashierInvoices
            ->filter(fn (PosCashierInvoice $invoice): bool => $this->isCashInvoice($invoice))
            ->sum('total_amount');
        $salesByDay = $cashierInvoices
            ->groupBy(fn (PosCashierInvoice $invoice): string => optional($invoice->closed_at)?->toDateString() ?: $from)
            ->map(fn (Collection $group, string $day): array => [
                'date' => $day,
                'invoices_count' => $group->count(),
                'sales_total' => (float) $group->sum('total_amount'),
            ])
            ->sortKeys()
            ->values();

        $recentOperations = AuditLog::query()
            ->with('user:id,name')
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to)
            ->whereIn('entity_type', [
                Order::class,
                OrderItem::class,
                DiningTable::class,
                TableSession::class,
                PosMenuItem::class,
                PosItemCategory::class,
                PosCashierInvoice::class,
                PosCashierInvoiceItem::class,
            ])
            ->latest('occurred_at')
            ->limit(30)
            ->get();

        return view('workspace.pos.reports.daily', [
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'invoice_sales_total' => (float) $cashierInvoices->sum('total_amount'),
                'invoices_count' => $cashierInvoices->count(),
                'cash_sales_total' => $cashSalesTotal,
                'total_quantity' => (int) $quantityByType->sum('quantity'),
                'orders_count' => $orders->count(),
                'paid_orders_count' => $orders->where('payment_status', 'paid')->count(),
                'unpaid_orders_count' => $orders->where('payment_status', '!=', 'paid')->count(),
                'table_orders_count' => (int) $orderChannelStats['table'],
                'takeaway_orders_count' => (int) $orderChannelStats['takeaway'],
                'delivery_orders_count' => (int) $orderChannelStats['delivery'],
            ],
            'salesByDay' => $salesByDay,
            'orderChannelStats' => $orderChannelStats,
            'quantityByType' => $quantityByType,
            'topTypes' => $quantityByType->take(10),
            'topItems' => $topItems,
            'salesByHour' => $salesByHour,
            'customerSummary' => $customerSummary,
            'recentOperations' => $recentOperations,
            'closedOrders' => $orders->whereNotNull('pos_cashier_invoice_id')->values(),
            'cashierInvoices' => $cashierInvoices,
            'allOrders' => $orders,
        ]);
    }

    private function isCashInvoice(PosCashierInvoice $invoice): bool
    {
        $method = strtolower(trim((string) data_get($invoice->metadata, 'payment_method', '')));

        return $method === 'cash' || str_contains($method, 'cash');
    }

    /**
     * @param  Collection<int,Order>  $orders
     * @return Collection<int,array<string,mixed>>
     */
    private function buildCustomerSummary(Collection $orders): Collection
    {
        return $orders
            ->filter(fn (Order $order): bool => $order->pos_cashier_invoice_id !== null)
            ->groupBy(function (Order $order): string {
                $name = $order->customer?->name
                    ?: data_get($order->metadata, 'customer_name')
                    ?: 'Walk-in';
                $phone = $order->customer?->phone ?: data_get($order->metadata, 'customer_phone');

                return trim($name.'|'.($phone ?: ''));
            })
            ->map(function (Collection $group, string $key): array {
                [$name, $phone] = array_pad(explode('|', $key, 2), 2, '');

                return [
                    'customer_name' => $name !== '' ? $name : 'Walk-in',
                    'customer_phone' => $phone !== '' ? $phone : '—',
                    'orders_count' => $group->count(),
                    'total_sales' => (float) $group->sum(fn (Order $order) => (float) $order->total_amount),
                    'last_order_at' => $group->max('placed_at'),
                ];
            })
            ->sortByDesc('total_sales')
            ->values();
    }

    /**
     * @param  Collection<int,Order>  $orders
     * @return Collection<int,array<string,mixed>>
     */
    private function buildSalesByHour(Collection $orders): Collection
    {
        return $orders
            ->groupBy(fn (Order $order): string => $order->placed_at?->format('H:00') ?: '00:00')
            ->map(fn (Collection $group, string $hour): array => [
                'hour' => $hour,
                'orders_count' => $group->count(),
                'sales_total' => (float) $group->sum(fn (Order $order) => (float) $order->total_amount),
            ])
            ->sortBy('hour')
            ->values();
    }
}
