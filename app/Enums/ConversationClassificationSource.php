<?php

namespace App\Enums;

enum ConversationClassificationSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case System = 'system';
}
