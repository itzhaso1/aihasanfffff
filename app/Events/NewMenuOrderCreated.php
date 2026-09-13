<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a customer places (or merges into) an order from the public QR menu.
 * Broadcast-ready for Reverb/Pusher; cashiers can also poll as a fallback.
 */
class NewMenuOrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Order $order) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workspace.'.$this->order->workspace_id.'.pos'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pos.menu-order.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $order = $this->order->loadMissing(['items', 'table:id,name']);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'workspace_id' => $order->workspace_id,
            'dining_table_id' => $order->dining_table_id,
            'table_name' => $order->table?->name,
            'pos_status' => $order->pos_status,
            'total_amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'notes' => $order->notes,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'total_amount' => (float) $item->total_amount,
            ])->values()->all(),
            'placed_at' => optional($order->placed_at)?->toIso8601String(),
        ];
    }
}
