<?php

namespace App\Enums;

enum InboxAgentMemoryScope: string
{
    case Customer = 'customer';
    case Conversation = 'conversation';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
