<?php

namespace App\Services\Channel\Outbound\Contracts;

use App\Models\Conversation;
use App\Models\Message;

interface ChannelOutboundDriver
{
    public function supports(string $channel): bool;

    /**
     * Queue provider delivery for an already-persisted outbound message.
     */
    public function queueOutbound(Conversation $conversation, Message $message): void;
}
