<?php

namespace App\Events;

use App\Models\TableSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableSessionClosed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly TableSession $session) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->session->workspace_id.'.pos')];
    }

    public function broadcastAs(): string
    {
        return 'pos.table-session.closed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->session->id,
            'dining_table_id' => $this->session->dining_table_id,
            'status' => $this->session->status,
            'closed_at' => optional($this->session->closed_at)?->toIso8601String(),
        ];
    }
}
