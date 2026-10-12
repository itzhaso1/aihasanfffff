<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerActivityEventType: string
{
    case SessionStarted = 'session_started';

    case SessionEnded = 'session_ended';

    case MessageSent = 'message_sent';

    case ConversationAssigned = 'conversation_assigned';

    public function label(): string
    {
        return match ($this) {
            self::SessionStarted => 'بدء جلسة',
            self::SessionEnded => 'إنهاء جلسة',
            self::MessageSent => 'إرسال رسالة',
            self::ConversationAssigned => 'إسناد محادثة',
        };
    }
}
