<?php

namespace App\Services\AI\Inbox\Memory\Contracts;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryExtractionResult;

interface InboxAgentMemoryExtractor
{
    public function extract(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
    ): InboxAgentMemoryExtractionResult;
}
