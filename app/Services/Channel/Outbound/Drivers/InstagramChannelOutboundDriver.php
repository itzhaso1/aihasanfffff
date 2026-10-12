<?php

namespace App\Services\Channel\Outbound\Drivers;

use App\Jobs\SendChannelOutboundMessage;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channel\Outbound\Contracts\ChannelOutboundDriver;
use Illuminate\Database\UniqueConstraintViolationException;

final class InstagramChannelOutboundDriver implements ChannelOutboundDriver
{
    public function supports(string $channel): bool
    {
        return $channel === 'instagram';
    }

    public function queueOutbound(Conversation $conversation, Message $message): void
    {
        try {
            $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->firstOrCreate(
                [
                    'workspace_id' => $conversation->workspace_id,
                    'message_id' => $message->id,
                ],
                [
                    'conversation_id' => $conversation->id,
                    'channel' => 'instagram',
                    'status' => ChannelOutboundDelivery::STATUS_QUEUED,
                    'attempts' => 0,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $delivery = ChannelOutboundDelivery::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('message_id', $message->id)
                ->first();

            if (! $delivery) {
                return;
            }
        }

        if ($delivery->status === ChannelOutboundDelivery::STATUS_SENT) {
            return;
        }

        SendChannelOutboundMessage::dispatch(
            (int) $delivery->id,
            (int) $conversation->workspace_id,
        );
    }
}
