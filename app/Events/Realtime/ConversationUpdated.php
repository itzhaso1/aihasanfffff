<?php

namespace App\Events\Realtime;

use App\Http\Resources\Mobile\MessageResource;
use App\Models\Conversation;
use App\Services\Conversation\ConversationResponderService;
use App\Services\Workforce\WorkforceInboxAttribution;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public ?string $correlationId;

    public function __construct(public Conversation $conversation, ?string $correlationId = null)
    {
        $this->correlationId = $correlationId ?? CorrelationContext::id();
    }

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
        return app(WorkspaceContext::class)->runForId((int) $this->conversation->workspace_id, function (): array {
            $this->conversation->loadMissing([
                'customer:id,name,phone',
                'lastMessage',
                'assignedUser:id,name',
                'assignedWorkerEngagement.workerProfile:id,user_id,display_name',
            ]);

            $latest = $this->conversation->lastMessage;
            $customer = $this->conversation->customer;
            $preview = $latest ? Str::limit($latest->content, 90) : '';

            return [
                'id' => $this->conversation->id,
                'workspace_id' => $this->conversation->workspace_id,
                'status' => (string) $this->conversation->status,
                'channel' => (string) $this->conversation->channel,
                'ai_enabled' => (bool) $this->conversation->ai_enabled,
                'ai_replying' => $this->conversation->isAiReplying(),
                'last_message' => $preview,
                'last_message_preview' => $preview,
                'last_message_direction' => $latest ? (string) $latest->direction : 'inbound',
                'last_message_data' => $latest ? (new MessageResource($latest))->resolve() : null,
                'last_message_at' => optional($this->conversation->last_message_at)?->toIso8601String(),
                'last_message_at_human' => optional($this->conversation->last_message_at)?->diffForHumans() ?? '',
                'handoff_status' => $this->conversation->handoff_status instanceof \BackedEnum
                    ? $this->conversation->handoff_status->value
                    : (string) ($this->conversation->handoff_status ?? 'none'),
                'assigned_user_id' => $this->conversation->assigned_user_id,
                'assigned_user_name' => $this->conversation->assignedUser?->name,
                'assigned_user' => $this->conversation->assignedUser ? [
                    'id' => $this->conversation->assignedUser->id,
                    'name' => $this->conversation->assignedUser->name,
                ] : null,
                'assigned_worker_engagement_id' => $this->conversation->assigned_worker_engagement_id,
                'assigned_participant_type' => WorkforceInboxAttribution::typeForConversation($this->conversation)?->value,
                'responder' => app(ConversationResponderService::class)->determineResponder($this->conversation),
                'active_responder' => app(ConversationResponderService::class)->determineResponder($this->conversation),
                'last_message_sender' => $this->conversation->lastMessageSender(),
                'customer' => $customer ? [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ] : null,
                'broadcast_at' => now()->toIso8601String(),
                'correlation_id' => $this->correlationId ?? CorrelationContext::id(),
            ];
        });
    }
}
