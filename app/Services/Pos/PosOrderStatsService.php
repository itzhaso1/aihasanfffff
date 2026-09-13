<?php

namespace App\Services\Pos;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PosOrderStatsService
{
    /**
     * @return array{
     *     date: string,
     *     table: int,
     *     takeaway: int,
     *     delivery: int,
     *     total: int,
     *     open_table: int,
     *     open_takeaway: int,
     *     open_delivery: int,
     *     open_total: int
     * }
     */
    /**
     * @return array{from: string, to: string, date: string}
     */
    public function resolveReportWindow(?string $date, ?string $from, ?string $to): array
    {
        $single = filled($date) ? Carbon::parse($date)->toDateString() : now()->toDateString();
        $start = filled($from) ? Carbon::parse($from)->toDateString() : $single;
        $end = filled($to) ? Carbon::parse($to)->toDateString() : (filled($from) ? $start : $single);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [
            'from' => $start,
            'to' => $end,
            'date' => $end,
        ];
    }

    public function channelCounts(?Carbon $day = null, ?Carbon $endDay = null): array
    {
        $from = ($day ?? now())->toDateString();
        $to = ($endDay ?? $day ?? now())->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $todayRows = $this->groupedCounts(
            Order::query()
                ->whereIn('source', ['pos', 'qr_menu'])
                ->where('pos_status', '!=', 'cancelled')
                ->whereDate('placed_at', '>=', $from)
                ->whereDate('placed_at', '<=', $to)
        );

        $openRows = $this->groupedCounts(
            Order::query()
                ->whereIn('source', ['pos', 'qr_menu'])
                ->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready', 'delivered'])
                ->whereNull('pos_cashier_invoice_id')
        );

        return [
            'date' => $from,
            'table' => (int) ($todayRows['table'] ?? 0),
            'takeaway' => (int) ($todayRows['takeaway'] ?? 0),
            'delivery' => (int) ($todayRows['delivery'] ?? 0),
            'total' => (int) array_sum($todayRows),
            'open_table' => (int) ($openRows['table'] ?? 0),
            'open_takeaway' => (int) ($openRows['takeaway'] ?? 0),
            'open_delivery' => (int) ($openRows['delivery'] ?? 0),
            'open_total' => (int) array_sum($openRows),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Order>  $query
     * @return array<string, int>
     */
    private function groupedCounts($query): array
    {
        $channelExpression = "CASE
            WHEN order_type = 'delivery' THEN 'delivery'
            WHEN order_type = 'takeaway' THEN 'takeaway'
            WHEN order_type = 'table' THEN 'table'
            WHEN dining_table_id IS NOT NULL THEN 'table'
            ELSE 'takeaway'
        END";

        $rows = $query
            ->selectRaw("{$channelExpression} as channel")
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy(DB::raw($channelExpression))
            ->pluck('aggregate', 'channel')
            ->all();

        return [
            'table' => (int) ($rows['table'] ?? 0),
            'takeaway' => (int) ($rows['takeaway'] ?? 0),
            'delivery' => (int) ($rows['delivery'] ?? 0),
        ];
    }
}
