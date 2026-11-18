<?php

namespace App\Events\Realtime;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Mobile\MessageAttachmentService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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

        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'workspace_id' => $this->message->workspace_id,
            'direction' => $this->message->direction,
            'message_type' => $this->message->message_type,
            'content' => $this->message->content,
            'created_at' => optional($this->message->created_at)?->toIso8601String(),
            'attachments' => $readyAttachments,
        ];
    }
}
