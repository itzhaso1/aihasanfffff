<?php

namespace App\Enums;

enum InboxAgentMemoryStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Deleted = 'deleted';
}
