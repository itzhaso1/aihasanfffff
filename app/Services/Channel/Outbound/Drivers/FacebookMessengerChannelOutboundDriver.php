<?php

namespace App\Services\Channel\Outbound\Drivers;

final class FacebookMessengerChannelOutboundDriver extends AbstractChannelOutboundDriver
{
    protected function channelName(): string
    {
        return 'facebook_messenger';
    }
}
