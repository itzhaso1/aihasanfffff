<?php

namespace App\Services\Finance\Assistant\Conversation;

use App\Models\Finance\FinanceAiConversation;
use App\Models\Finance\FinanceAiMessage;

final class FinanceAgentConversationContext
{
    /**
     * @param  list<FinanceAiMessage>  $priorMessages
     */
    public function __construct(
        public readonly FinanceAiConversation $conversation,
        public readonly array $priorMessages,
        public readonly string $usageEventKey,
    ) {}
}
