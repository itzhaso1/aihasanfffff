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
            $missing = [];
            if ($this->conversation->customer_id !== null && ! $this->conversation->relationLoaded('customer')) {
                $missing[] = 'customer:id,name,phone';
            }
            if (! $this->conversation->relationLoaded('lastMessage')) {
                $missing[] = 'lastMessage';
            }
            if ($this->conversation->assigned_user_id !== null && ! $this->conversation->relationLoaded('assignedUser')) {
                $missing[] = 'assignedUser:id,name';
            }
            if ($this->conversation->assigned_worker_engagement_id !== null && ! $this->conversation->relationLoaded('assignedWorkerEngagement')) {
                $missing[] = 'assignedWorkerEngagement.workerProfile:id,user_id,display_name';
            }
            if ($missing !== []) {
                $this->conversation->loadMissing($missing);
            }

            $latest = $this->conversation->lastMessage;
            if ($latest !== null) {
                if (
                    $latest->customer_id !== null
                    && $latest->customer_id === $this->conversation->customer_id
                    && ! $latest->relationLoaded('customer')
                    && $this->conversation->relationLoaded('customer')
                ) {
                    $latest->setRelation('customer', $this->conversation->customer);
                }

                $latestMissing = [];
                if ($latest->user_id !== null && ! $latest->relationLoaded('user')) {
                    $latestMissing[] = 'user:id,name';
                }
                if ($latest->customer_id !== null && ! $latest->relationLoaded('customer')) {
                    $latestMissing[] = 'customer:id,name';
                }
                if (! $latest->relationLoaded('attachments')) {
                    $latestMissing[] = 'attachments';
                }
                if ($latest->worker_engagement_id !== null && ! $latest->relationLoaded('workerEngagement')) {
                    $latestMissing[] = 'workerEngagement.workerProfile:id,user_id,display_name';
                }
                if ($latestMissing !== []) {
                    $latest->loadMissing($latestMissing);
                }
            }

            $customer = $this->conversation->customer;
            $preview = $latest ? Str::limit($latest->content, 90) : '';
            $responder = app(ConversationResponderService::class)->determineResponder($this->conversation);
            $lastMessageData = $latest ? (new MessageResource($latest))->resolve() : null;
            $lastMessageSender = $this->conversation->lastMessageSender();

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
                'last_message_data' => $lastMessageData,
                'last_message_at' => optional($this->conversation->last_message_at)?->toIso8601String(),
                'last_message_at_human' => optional($this->conversation->last_message_at)?->diffForHumans() ?? '',
                'handoff_status' => $this->conversation->handoff_status instanceof \BackedEnum
                    ? $this->conversation->handoff_status->value
                    : (string) ($this->conversation->handoff_status ?? 'none'),
                'handoff_version' => (int) ($this->conversation->handoff_version ?? 1),
                'orders_updated_at' => data_get($this->conversation->metadata, 'orders_updated_at'),
                'updated_at' => optional($this->conversation->updated_at)?->toIso8601String(),
                'assigned_user_id' => $this->conversation->assigned_user_id,
                'assigned_user_name' => $this->conversation->assignedUser?->name,
                'assigned_user' => $this->conversation->assignedUser ? [
                    'id' => $this->conversation->assignedUser->id,
                    'name' => $this->conversation->assignedUser->name,
                ] : null,
                'assigned_worker_engagement_id' => $this->conversation->assigned_worker_engagement_id,
                'assigned_participant_type' => WorkforceInboxAttribution::typeForConversation($this->conversation)?->value,
                'responder' => $responder,
                'active_responder' => $responder,
                'last_message_sender' => $lastMessageSender,
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
