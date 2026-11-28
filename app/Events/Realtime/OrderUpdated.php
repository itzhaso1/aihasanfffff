<?php

declare(strict_types=1);

namespace App\Events\Realtime;

use App\Models\Order;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public ?string $correlationId;

    public function __construct(
        public Order $order,
        public string $action = 'updated',
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? CorrelationContext::id();
    }

    public function broadcastConnection(): ?string
    {
        $connection = config('inbox.realtime.broadcast_connection');

        return is_string($connection) && $connection !== '' && $connection !== 'null'
            ? $connection
            : null;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('workspace.'.$this->order->workspace_id.'.orders'),
            new PrivateChannel('workspace.'.$this->order->workspace_id.'.conversations'),
        ];

        if ($this->order->conversation_id !== null) {
            $channels[] = new PrivateChannel('workspace.'.$this->order->workspace_id.'.conversation.'.$this->order->conversation_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(WorkspaceContext::class)->runForId((int) $this->order->workspace_id, function (): array {
            $missing = [];
            if ($this->order->delivery_location_id !== null && ! $this->order->relationLoaded('deliveryLocation')) {
                $missing[] = 'deliveryLocation';
            }
            if ($missing !== []) {
                $this->order->loadMissing($missing);
            }

            return [
                'id' => (int) $this->order->id,
                'workspace_id' => (int) $this->order->workspace_id,
                'conversation_id' => $this->order->conversation_id !== null ? (int) $this->order->conversation_id : null,
                'customer_id' => $this->order->customer_id !== null ? (int) $this->order->customer_id : null,
                'order_number' => $this->order->order_number,
                'status' => (string) $this->order->status,
                'shipping_amount' => (string) ($this->order->shipping_amount ?? '0.00'),
                'total_amount' => (string) $this->order->total_amount,
                'currency' => (string) ($this->order->currency ?: 'SAR'),
                'shipping_address' => data_get($this->order->metadata, 'shipping_address'),
                'delivery_location' => $this->order->deliveryLocation?->toSummaryArray()
                    ?? data_get($this->order->metadata, 'delivery_location'),
                'action' => $this->action,
                'updated_at' => optional($this->order->updated_at)?->toIso8601String(),
                'broadcast_at' => now()->toIso8601String(),
                'correlation_id' => $this->correlationId ?? CorrelationContext::id(),
            ];
        });
    }
}
