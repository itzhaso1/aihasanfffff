<?php

namespace App\Events\Realtime;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    public function broadcastConnection(): ?string
    {
        $connection = config('inbox.realtime.broadcast_connection');

        return is_string($connection) && $connection !== '' && $connection !== 'null'
            ? $connection
            : null;
    }

    public function broadcastQueue(): string
    {
        return (string) config('inbox.queues.realtime', 'realtime');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workspace.'.$this->conversation->workspace_id.'.conversations'),
            new PrivateChannel('workspace.'.$this->conversation->workspace_id.'.conversation.'.$this->conversation->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $metadata = is_array($this->conversation->metadata) ? $this->conversation->metadata : [];

        return [
            'id' => $this->conversation->id,
            'workspace_id' => $this->conversation->workspace_id,
            'status' => $this->conversation->status,
            'channel' => $this->conversation->channel,
            'last_message_at' => optional($this->conversation->last_message_at)?->toIso8601String(),
            'unread_count' => (int) ($metadata['unread_count'] ?? 0),
        ];
    }
}
