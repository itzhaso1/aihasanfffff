<?php

namespace App\Events\Realtime;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Mobile\MessageAttachmentService;
use App\Services\Workforce\WorkforceInboxAttribution;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class MessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Prevent broadcasting before database transactions commit.
     */
    public bool $afterCommit = true;

    public function __construct(public Message $message) {}

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
            new PrivateChannel('workspace.'.$this->message->workspace_id.'.conversations'),
            new PrivateChannel('workspace.'.$this->message->workspace_id.'.conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return app(WorkspaceContext::class)->runForId((int) $this->message->workspace_id, function (): array {
            $this->message->loadMissing([
                'attachments',
                'user:id,name',
                'customer:id,name',
                'workerEngagement.workerProfile:id,user_id,display_name',
                'workerEngagement.workerProfile.user:id,name',
            ]);

            $readyAttachments = $this->message->attachments ? $this->message->attachments->map(function ($attachment): array {
                $ready = ($attachment->status ?? 'stored') === MessageAttachment::STATUS_STORED;

                return [
                    'id' => $attachment->id,
                    'kind' => $attachment->kind,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'status' => $attachment->status,
                    'width' => $attachment->width,
                    'height' => $attachment->height,
                    'download_url' => $ready
                        ? app(MessageAttachmentService::class)->workspaceDownloadUrl($attachment)
                        : null,
                ];
            })->values()->all() : [];

            $senderName = $this->resolveSenderName();
            $participantType = WorkforceInboxAttribution::typeForMessage($this->message)?->value;
            $idempotencyKey = $this->message->metadata['idempotency_key'] ?? $this->message->metadata['client_id'] ?? null;
            $isInternalNote = $this->message->direction === 'internal_note'
                || $this->message->message_type === 'system'
                || (bool) data_get($this->message->metadata, 'handoff_acknowledgement');

            return [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'workspace_id' => $this->message->workspace_id,
                'direction' => $this->message->direction,
                'message_type' => $this->message->message_type,
                'content' => $this->message->content,
                'ai_generated' => (bool) $this->message->ai_generated,
                'is_internal_note' => $isInternalNote,
                'sender_name' => $senderName,
                'user_name' => $senderName,
                'participant_type' => $participantType,
                'user_id' => $this->message->user_id,
                'user' => $this->message->user ? [
                    'id' => $this->message->user->id,
                    'name' => $this->message->user->name,
                ] : null,
                'customer' => $this->message->customer ? [
                    'id' => $this->message->customer->id,
                    'name' => $this->message->customer->name,
                ] : null,
                'worker_engagement_id' => $this->message->worker_engagement_id,
                'client_id' => $idempotencyKey,
                'idempotency_key' => $idempotencyKey,
                'created_at' => optional($this->message->created_at)?->toIso8601String(),
                'created_at_time' => optional($this->message->created_at)?->format('H:i') ?? Carbon::now()->format('H:i'),
                'attachments' => $readyAttachments,
            ];
        });
    }

    private function resolveSenderName(): string
    {
        if ($this->message->ai_generated) {
            return 'مشعل AI';
        }

        if ($this->message->worker_engagement_id !== null) {
            $engagement = $this->message->workerEngagement;
            $profile = $engagement?->workerProfile;
            if ($profile?->display_name) {
                return $profile->display_name;
            }
            if ($profile?->user?->name) {
                return $profile->user->name;
            }
            if ($this->message->user?->name) {
                return $this->message->user->name;
            }

            return 'موظف مستقل';
        }

        if ($this->message->user_id !== null) {
            return $this->message->user?->name ?? 'فريق العمل';
        }

        if ($this->message->customer_id !== null) {
            return $this->message->customer?->name ?? 'العميل';
        }

        return $this->message->direction === 'outbound' ? 'فريق العمل' : 'العميل';
    }
}
