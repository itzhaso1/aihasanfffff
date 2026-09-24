<?php

namespace App\Services\Finance\Assistant\Conversation;

use App\Models\Finance\FinanceAiConversation;
use App\Models\Finance\FinanceAiMessage;

final class FinanceAiConversationTurn
{
    public function __construct(
        public readonly FinanceAiConversation $conversation,
        public readonly FinanceAiMessage $userMessage,
    ) {}

    public function usageEventKey(): string
    {
        return 'finance-assistant:conv:'.$this->conversation->id.':msg:'.$this->userMessage->id;
    }
}
