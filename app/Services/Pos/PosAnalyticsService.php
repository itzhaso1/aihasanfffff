<?php

namespace App\Services\Pos;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosCashierInvoice;
use App\Models\Workspace;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

class PosAnalyticsService
{
    /**
     * @return array{invoices_count: int, total: string, currency: string}
     */
    public function salesTotal(Workspace $workspace, string $from, string $to): array
    {
        $row = PosCashierInvoice::query()
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to)
            ->selectRaw('COUNT(*) as invoices_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
            ->first();

        return [
            'invoices_count' => (int) ($row->invoices_count ?? 0),
            'total' => Money::of($row->total_amount ?? 0),
            'currency' => $this->currency($workspace),
        ];
    }

    /**
     * @return list<array{product: string, quantity: int, revenue: string}>
     */
    public function topProducts(Workspace $workspace, string $from, string $to, int $limit = 10): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.workspace_id', $workspace->id)
            ->whereNotNull('orders.pos_cashier_invoice_id')
            ->whereIn('orders.source', ['pos', 'qr_menu'])
            ->whereDate('orders.placed_at', '>=', $from)
            ->whereDate('orders.placed_at', '<=', $to)
            ->selectRaw('order_items.product_name as product')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(order_items.total_amount), 0) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return $rows->map(fn ($row): array => [
            'product' => (string) $row->product,
            'quantity' => (int) $row->quantity,
            'revenue' => Money::of($row->revenue ?? 0),
        ])->all();
    }

    /**
     * @return list<array{customer_id: ?int, customer_name: string, orders_count: int, revenue: string}>
     */
    public function topCustomers(Workspace $workspace, string $from, string $to, int $limit = 10): array
    {
        $rows = Order::query()
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'orders.customer_id')
                    ->on('customers.workspace_id', '=', 'orders.workspace_id');
            })
            ->whereNotNull('orders.pos_cashier_invoice_id')
            ->whereIn('orders.source', ['pos', 'qr_menu'])
            ->whereDate('orders.placed_at', '>=', $from)
            ->whereDate('orders.placed_at', '<=', $to)
            ->groupBy('customers.id', 'customers.name')
            ->selectRaw('customers.id as customer_id')
            ->selectRaw("COALESCE(customers.name, 'Walk-in') as customer_name")
            ->selectRaw('COUNT(orders.id) as orders_count')
            ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as revenue')
            ->orderByDesc('revenue')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return $rows->map(fn ($row): array => [
            'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
            'customer_name' => (string) $row->customer_name,
            'orders_count' => (int) $row->orders_count,
            'revenue' => Money::of($row->revenue ?? 0),
        ])->all();
    }

    /**
     * @return array{available: bool, methods: list<array{method: string, orders_count: int, sales_total: string}>, reason?: string}
     */
    public function paymentMethods(Workspace $workspace, string $from, string $to): array
    {
        $driver = DB::getDriverName();
        $methodExpression = $driver === 'sqlite'
            ? "COALESCE(json_extract(orders.metadata, '$.payment_method'), 'unknown')"
            : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(orders.metadata, '$.payment_method')), 'unknown')";

        $rows = Order::query()
            ->whereNotNull('pos_cashier_invoice_id')
            ->whereIn('source', ['pos', 'qr_menu'])
            ->whereDate('placed_at', '>=', $from)
            ->whereDate('placed_at', '<=', $to)
            ->selectRaw("{$methodExpression} as method")
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as sales_total')
            ->groupBy(DB::raw($methodExpression))
            ->get();

        if ($rows->isEmpty()) {
            return [
                'available' => false,
                'methods' => [],
                'reason' => 'No closed POS orders found for this period.',
            ];
        }

        $methods = $rows->map(fn ($row): array => [
            'method' => trim((string) $row->method, "\"'") ?: 'unknown',
            'orders_count' => (int) $row->orders_count,
            'sales_total' => Money::of($row->sales_total ?? 0),
        ])->values()->all();

        $known = collect($methods)->contains(fn (array $row): bool => ! in_array(strtolower($row['method']), ['unknown', 'paid', 'pending'], true));
        if (! $known) {
            $cashTotal = PosCashierInvoice::query()
                ->whereDate('closed_at', '>=', $from)
                ->whereDate('closed_at', '<=', $to)
                ->where(function ($query): void {
                    $query->whereRaw("LOWER(COALESCE(json_extract(metadata, '$.payment_method'), '')) LIKE '%cash%'");
                })
                ->sum('total_amount');

            if ((float) $cashTotal <= 0) {
                return [
                    'available' => false,
                    'methods' => $methods,
                    'reason' => 'Payment method data is insufficient for this period.',
                ];
            }
        }

        return [
            'available' => true,
            'methods' => $methods,
        ];
    }

    /**
     * @return array{current: array{from: string, to: string, total: string}, previous: array{from: string, to: string, total: string}, delta: string}
     */
    public function compareSalesPeriods(
        Workspace $workspace,
        string $currentFrom,
        string $currentTo,
        string $previousFrom,
        string $previousTo,
    ): array {
        $current = $this->salesTotal($workspace, $currentFrom, $currentTo);
        $previous = $this->salesTotal($workspace, $previousFrom, $previousTo);

        return [
            'current' => [
                'from' => $currentFrom,
                'to' => $currentTo,
                'total' => $current['total'],
            ],
            'previous' => [
                'from' => $previousFrom,
                'to' => $previousTo,
                'total' => $previous['total'],
            ],
            'delta' => Money::sub($current['total'], $previous['total']),
            'currency' => $current['currency'],
        ];
    }

    private function currency(Workspace $workspace): string
    {
        $currency = data_get($workspace->settings ?? [], 'pos.currency');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'SAR';
    }
}
