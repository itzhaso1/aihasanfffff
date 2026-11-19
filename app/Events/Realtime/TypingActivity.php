<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TypingActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $workspaceId,
        public int $conversationId,
        public string $senderType,
        public string $senderName,
        public bool $isTyping = true,
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
            new PrivateChannel('workspace.'.$this->workspaceId.'.conversation.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'typing.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'conversation_id' => $this->conversationId,
            'sender_type' => $this->senderType,
            'sender_name' => $this->senderName,
            'is_typing' => $this->isTyping,
        ];
    }
}
