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

    /**
     * Auto-reply is allowed only in `none`. Resolved means the human
     * handoff finished; AI stays off until resumeAi moves the row back to none.
     */
    public function allowsAiAutoReply(): bool
    {
        return $this === self::None;
    }
}
