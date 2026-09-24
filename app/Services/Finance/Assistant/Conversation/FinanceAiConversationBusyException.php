<?php

namespace App\Services\Finance\Assistant\Conversation;

use RuntimeException;

final class FinanceAiConversationBusyException extends RuntimeException
{
    public static function inProgress(): self
    {
        return new self('This conversation is already processing another question.');
    }
}
