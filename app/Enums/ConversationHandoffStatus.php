<?php

namespace App\Enums;

enum ConversationHandoffStatus: string
{
    case None = 'none';
    case Requested = 'requested';
    case Queued = 'queued';
    case Assigned = 'assigned';
    case Active = 'active';
    case Resolved = 'resolved';

    public function blocksAi(): bool
    {
        return match ($this) {
            self::None, self::Resolved => false,
            default => true,
        };
    }
}
