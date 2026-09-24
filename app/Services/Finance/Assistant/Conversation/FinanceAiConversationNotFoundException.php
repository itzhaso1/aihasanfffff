<?php

namespace App\Services\Finance\Assistant\Conversation;

use RuntimeException;

final class FinanceAiConversationNotFoundException extends RuntimeException
{
    public static function forLookup(): self
    {
        return new self('Conversation not found.');
    }
}
