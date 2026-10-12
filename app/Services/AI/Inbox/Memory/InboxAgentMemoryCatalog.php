<?php

namespace App\Services\AI\Inbox\Memory;

final class InboxAgentMemoryCatalog
{
    /** @var list<string> */
    public const KEYS = [
        'preferred_name',
        'preferred_size',
        'preferred_color',
        'communication_preference',
        'product_preference',
        'customer_note',
        'follow_up_request',
        'comparison_context',
    ];

    /** @var list<string> */
    public const CONVERSATION_KEYS = [
        'follow_up_request',
        'comparison_context',
        'customer_note',
    ];

    /** @var list<string> */
    public const CUSTOMER_KEYS = [
        'preferred_name',
        'preferred_size',
        'preferred_color',
        'communication_preference',
        'product_preference',
        'customer_note',
    ];

    public static function isAllowedKey(string $key, string $scope): bool
    {
        if (! in_array($key, self::KEYS, true)) {
            return false;
        }

        return match ($scope) {
            'conversation' => in_array($key, self::CONVERSATION_KEYS, true),
            'customer' => in_array($key, self::CUSTOMER_KEYS, true),
            default => false,
        };
    }
}
