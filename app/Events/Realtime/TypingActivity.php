<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TypingActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<array<string, mixed>>  $typingUsers
     */
    public function __construct(
        public int $workspaceId,
        public int $conversationId,
        public string $senderType,
        public string $senderName,
        public bool $isTyping = true,
        public ?int $userId = null,
        public array $typingUsers = [],
        public string $typingSummary = '',
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
            'user_id' => $this->userId,
            'is_typing' => $this->isTyping,
            'typing_users' => $this->typingUsers,
            'typing_summary' => $this->typingSummary !== '' ? $this->typingSummary : ($this->isTyping ? "{$this->senderName} يكتب الآن..." : ''),
        ];
    }
}
