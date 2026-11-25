<?php

namespace App\Services\Channel\Outbound\Drivers;

final class WhatsAppChannelOutboundDriver extends AbstractChannelOutboundDriver
{
    protected function channelName(): string
    {
        return 'whatsapp';
    }
}
