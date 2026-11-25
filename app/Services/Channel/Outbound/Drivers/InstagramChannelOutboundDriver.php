<?php

namespace App\Services\Channel\Outbound\Drivers;

final class InstagramChannelOutboundDriver extends AbstractChannelOutboundDriver
{
    protected function channelName(): string
    {
        return 'instagram';
    }
}
