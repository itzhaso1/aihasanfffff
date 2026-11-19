<?php

declare(strict_types=1);

namespace App\Events\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class EmployeeLeftConversation implements ShouldBroadcastNow
{
    use SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $presentEmployees
     */
    public function __construct(
        public int $workspaceId,
        public int $conversationId,
        public int $userId,
        public string $employeeName,
        public int $presentCount,
        public array $presentEmployees = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}.conversation.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'employee.left';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'employee_name' => $this->employeeName,
            'present_count' => $this->presentCount,
            'present_employees' => $this->presentEmployees,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
