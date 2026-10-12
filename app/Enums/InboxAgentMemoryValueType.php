<?php

namespace App\Enums;

enum InboxAgentMemoryValueType: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Json = 'json';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
