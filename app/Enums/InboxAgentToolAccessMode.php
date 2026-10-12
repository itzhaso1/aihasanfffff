<?php

namespace App\Enums;

enum InboxAgentToolAccessMode: string
{
    case Read = 'read';
    case Write = 'write';
}
