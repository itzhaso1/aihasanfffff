<?php

namespace App\Services\Pos;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosOrderReturn;
use App\Models\PosOrderReturnItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosReturnService
{
    /**
     * Create a POS return/refund audit record. Does not call payment gateways.
     *
     * @param  array{
     *     reason?: string|null,
     *     items: list<array{order_item_id:int, qty:int, amount?:float|null}>
     * }  $payload
     */
    public function createReturn(Workspace $workspace, Order $order, array $payload, ?User $actor): PosOrderReturn
    {
        if ((int) $order->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('الطلب لا ينتمي إلى مساحة العمل الحالية.');
        }

        if (! in_array($order->source, ['pos', 'qr_menu'], true)) {
            throw new RuntimeException('المرتجعات متاحة لطلبات POS فقط.');
        }

        if ($order->pos_status === 'cancelled') {
            throw new RuntimeException('لا يمكن إرجاع طلب ملغي.');
        }

        $lines = collect($payload['items'] ?? []);
        if ($lines->isEmpty()) {
            throw new RuntimeException('يجب تحديد عناصر المرتجع.');
        }

        return DB::transaction(function () use ($workspace, $order, $payload, $actor, $lines): PosOrderReturn {
            $orderItems = OrderItem::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('order_id', $order->id)
                ->get()
                ->keyBy('id');

            $return = PosOrderReturn::query()->create([
                'workspace_id' => $workspace->id,
                'order_id' => $order->id,
                'user_id' => $actor?->id,
                'reason' => $payload['reason'] ?? null,
                'status' => 'pending',
                'total' => 0,
                'metadata' => [
                    'created_by_name' => $actor?->name,
                    'payment_status_at_create' => $order->payment_status,
                ],
            ]);

            $total = 0.0;

            foreach ($lines as $line) {
                $orderItemId = (int) ($line['order_item_id'] ?? 0);
                $qty = max(1, (int) ($line['qty'] ?? 0));
                /** @var OrderItem|null $orderItem */
                $orderItem = $orderItems->get($orderItemId);

                if (! $orderItem) {
                    throw new RuntimeException('أحد عناصر الطلب غير صالح للمرتجع.');
                }

                if ($qty > (int) $orderItem->quantity) {
                    throw new RuntimeException('كمية المرتجع أكبر من كمية العنصر.');
                }

                $unit = (float) $orderItem->unit_price;
                $amount = isset($line['amount'])
                    ? max(0, round((float) $line['amount'], 2))
                    : round($unit * $qty, 2);

                PosOrderReturnItem::query()->create([
                    'workspace_id' => $workspace->id,
                    'return_id' => $return->id,
                    'order_item_id' => $orderItem->id,
                    'qty' => $qty,
                    'amount' => $amount,
                ]);

                $total += $amount;
            }

            $return->update(['total' => round($total, 2)]);

            return $return->fresh(['items', 'order']);
        });
    }

    /**
     * Mark return as refunded and update order payment metadata (audit only; no gateway refund).
     */
    public function markRefunded(PosOrderReturn $return, ?User $actor = null): PosOrderReturn
    {
        if ($return->status === 'refunded') {
            return $return->fresh(['items', 'order']);
        }

        return DB::transaction(function () use ($return, $actor): PosOrderReturn {
            $locked = PosOrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $order = Order::withoutGlobalScopes()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $refunds = is_array($metadata['pos_refunds'] ?? null) ? $metadata['pos_refunds'] : [];
            $refunds[] = [
                'return_id' => $locked->id,
                'amount' => (float) $locked->total,
                'refunded_at' => now()->toIso8601String(),
                'refunded_by_user_id' => $actor?->id,
                'refunded_by_name' => $actor?->name,
                'gateway' => null,
                'note' => 'Status/audit refund only — no payment gateway call.',
            ];
            $metadata['pos_refunds'] = $refunds;
            $metadata['last_pos_refund_at'] = now()->toIso8601String();

            $orderTotal = (float) $order->total_amount;
            $refundedSum = round(array_sum(array_map(
                static fn (array $row): float => (float) ($row['amount'] ?? 0),
                $refunds
            )), 2);

            $paymentStatus = $order->payment_status;
            if ($order->payment_status === 'paid' || $refundedSum > 0) {
                $paymentStatus = $refundedSum + 0.009 >= $orderTotal ? 'refunded' : 'paid';
            }

            $order->update([
                'payment_status' => $paymentStatus,
                'metadata' => $metadata,
            ]);

            $locked->update([
                'status' => 'refunded',
                'metadata' => array_merge(
                    is_array($locked->metadata) ? $locked->metadata : [],
                    [
                        'refunded_at' => now()->toIso8601String(),
                        'refunded_by_user_id' => $actor?->id,
                    ]
                ),
            ]);

            return $locked->fresh(['items', 'order']);
        });
    }
}
