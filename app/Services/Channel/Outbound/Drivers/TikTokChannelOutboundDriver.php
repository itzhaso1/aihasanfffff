<?php

namespace App\Services\Channel\Outbound\Drivers;

final class TikTokChannelOutboundDriver extends AbstractChannelOutboundDriver
{
    protected function channelName(): string
    {
        return 'tiktok';
    }
}
