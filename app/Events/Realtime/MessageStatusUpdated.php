<?php

declare(strict_types=1);

namespace App\Events\Realtime;

use App\Models\Message;
use App\Support\Observability\CorrelationContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public ?string $errorReason = null,
    ) {}

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
        return [
            new PrivateChannel('workspace.'.$this->message->workspace_id.'.conversations'),
            new PrivateChannel('workspace.'.$this->message->workspace_id.'.conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.status_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $metadata = is_array($this->message->metadata) ? $this->message->metadata : [];
        $clientId = $metadata['client_id'] ?? $metadata['idempotency_key'] ?? null;

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'workspace_id' => $this->message->workspace_id,
            'client_id' => $clientId,
            'idempotency_key' => $clientId,
            'delivery_status' => $this->message->delivery_status,
            'delivered_at' => optional($this->message->delivered_at)?->toIso8601String(),
            'read_at' => optional($this->message->read_at)?->toIso8601String(),
            'error_reason' => $this->errorReason,
            'correlation_id' => CorrelationContext::id(),
            'broadcast_at' => now()->toIso8601String(),
        ];
    }
}
