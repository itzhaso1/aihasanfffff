<?php

namespace App\Services\Channel\Outbound\Drivers;

final class TelegramChannelOutboundDriver extends AbstractChannelOutboundDriver
{
    protected function channelName(): string
    {
        return 'telegram';
    }
}
