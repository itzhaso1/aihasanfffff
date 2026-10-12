<?php

namespace App\Services\Media;

use App\Exceptions\Media\UnsupportedOutboundMediaException;
use App\Jobs\Media\ProcessOutboundChannelMedia;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Support\Facades\Log;

final class OutboundChannelMediaService
{
    public function queueStoredAttachments(Conversation $conversation, Message $message): void
    {
        if ($message->direction !== 'outbound') {
            return;
        }

        $attachments = MessageAttachment::withoutGlobalScopes()
            ->where('message_id', $message->id)
            ->where('workspace_id', $message->workspace_id)
            ->where('status', MessageAttachment::STATUS_STORED)
            ->get();

        if ($attachments->isEmpty()) {
            return;
        }

        $channel = app(ChannelOutboundService::class)->resolveOutboundChannel($conversation);
        $supported = config('media.outbound.'.$channel, []);

        foreach ($attachments as $attachment) {
            if (! in_array($attachment->kind, $supported, true)) {
                Log::warning('media.outbound.unsupported', [
                    'workspace_id' => $message->workspace_id,
                    'message_id' => $message->id,
                    'channel' => $channel,
                    'kind' => $attachment->kind,
                ]);

                throw new UnsupportedOutboundMediaException(
                    "Channel {$channel} does not support outbound {$attachment->kind} media.",
                );
            }

            ProcessOutboundChannelMedia::dispatch(
                (int) $attachment->id,
                (int) $message->workspace_id,
                (int) $conversation->id,
            );
        }
    }
}
