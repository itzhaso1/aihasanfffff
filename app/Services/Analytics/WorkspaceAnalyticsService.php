<?php

namespace App\Services\Analytics;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkspaceAnalyticsService
{
    /**
     * @return array{
     *     from:string,
     *     to:string,
     *     revenue:float,
     *     orders_count:int,
     *     customers_new:int,
     *     conversations_count:int,
     *     ai_responses_count:int,
     *     conversion_rate:float,
     *     timeline:array<int, array{label:string,orders:int,revenue:float}>,
     *     peak_hours:array<string, int>,
     *     top_products:array<int, array{name:string,quantity:float,revenue:float}>,
     *     payment_status_breakdown:array<string, int>
     * }
     */
    public function summary(Workspace $workspace, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $fromAt = Carbon::parse($from)->startOfDay();
        $toAt = Carbon::parse($to)->endOfDay();
        $workspaceId = (int) $workspace->id;

        $revenue = (float) Payment::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$fromAt, $toAt])
            ->sum('amount');

        if ($revenue === 0.0) {
            $revenue = (float) Order::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->where('payment_status', 'paid')
                ->whereNull('deleted_at')
                ->sum('total_amount');
        }

        $ordersCount = (int) Order::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->whereNull('deleted_at')
            ->count();

        $customersNew = (int) Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->whereNull('deleted_at')
            ->count();

        $conversationsCount = (int) Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->count();

        $aiResponsesCount = (int) Message::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('sender_type', 'ai')
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->count();

        $conversionRate = $conversationsCount > 0
            ? round(($ordersCount / $conversationsCount) * 100, 1)
            : ($ordersCount > 0 ? 100.0 : 0.0);

        $topProducts = $this->topProducts($workspaceId, $fromAt, $toAt);
        $paymentBreakdown = $this->paymentStatusBreakdown($workspaceId, $fromAt, $toAt);
        $timeline = $this->buildTimeline($workspaceId, $fromAt, $toAt);
        $peakHours = $this->buildPeakHours($workspaceId, $fromAt, $toAt);

        return [
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
            'revenue' => round($revenue, 2),
            'orders_count' => $ordersCount,
            'customers_new' => $customersNew,
            'conversations_count' => $conversationsCount,
            'ai_responses_count' => $aiResponsesCount,
            'conversion_rate' => $conversionRate,
            'timeline' => $timeline,
            'peak_hours' => $peakHours,
            'top_products' => $topProducts,
            'payment_status_breakdown' => $paymentBreakdown,
        ];
    }

    /**
     * @return array<int, array{name:string,quantity:float,revenue:float}>
     */
    private function topProducts(int $workspaceId, CarbonInterface $fromAt, CarbonInterface $toAt): array
    {
        $items = OrderItem::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereHas('order', function ($query) use ($workspaceId, $fromAt, $toAt): void {
                $query->withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereBetween('created_at', [$fromAt, $toAt])
                    ->whereNull('deleted_at');
            })
            ->get(['product_name', 'sku', 'quantity', 'total_amount']);

        return $items
            ->groupBy(function (OrderItem $item): string {
                $name = trim((string) $item->product_name);
                if ($name !== '') {
                    return $name;
                }

                $sku = trim((string) $item->sku);

                return $sku !== '' ? $sku : 'منتج بدون اسم';
            })
            ->map(fn (Collection $group, string $name): array => [
                'name' => $name,
                'quantity' => (float) $group->sum('quantity'),
                'revenue' => round((float) $group->sum('total_amount'), 2),
            ])
            ->sortByDesc('quantity')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function paymentStatusBreakdown(int $workspaceId, CarbonInterface $fromAt, CarbonInterface $toAt): array
    {
        return Payment::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @return array<int, array{label:string,orders:int,revenue:float}>
     */
    private function buildTimeline(int $workspaceId, CarbonInterface $fromAt, CarbonInterface $toAt): array
    {
        $diffDays = $fromAt->diffInDays($toAt);
        $pointsCount = min(max((int) $diffDays, 7), 14);
        $step = max((int) floor($diffDays / $pointsCount), 1);

        $points = [];
        $cursor = $fromAt->copy();

        for ($i = 0; $i < $pointsCount; $i++) {
            $pointStart = $cursor->copy()->startOfDay();
            $pointEnd = $cursor->copy()->addDays($step - 1)->endOfDay();
            if ($pointEnd->greaterThan($toAt)) {
                $pointEnd = $toAt->copy();
            }

            $ordersCount = Order::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereBetween('created_at', [$pointStart, $pointEnd])
                ->whereNull('deleted_at')
                ->count();

            $revenue = (float) Payment::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$pointStart, $pointEnd])
                ->sum('amount');

            $points[] = [
                'label' => $pointStart->format('m/d'),
                'orders' => $ordersCount,
                'revenue' => round($revenue, 2),
            ];

            $cursor->addDays($step);
            if ($cursor->greaterThan($toAt)) {
                break;
            }
        }

        return $points;
    }

    /**
     * @return array<string, int>
     */
    private function buildPeakHours(int $workspaceId, CarbonInterface $fromAt, CarbonInterface $toAt): array
    {
        $messages = Message::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->limit(2000)
            ->get(['created_at']);

        $morning = 0;
        $afternoon = 0;
        $evening = 0;
        $night = 0;

        foreach ($messages as $msg) {
            $hour = (int) $msg->created_at?->hour;
            if ($hour >= 6 && $hour < 12) {
                $morning++;
            } elseif ($hour >= 12 && $hour < 18) {
                $afternoon++;
            } elseif ($hour >= 18 && $hour <= 23) {
                $evening++;
            } else {
                $night++;
            }
        }

        return [
            'الصباح (6 ص - 12 م)' => $morning,
            'بعد الظهر (12 م - 6 م)' => $afternoon,
            'المساء (6 م - 12 ص)' => $evening,
            'الليل (12 ص - 6 ص)' => $night,
        ];
    }
}
