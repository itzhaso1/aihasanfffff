<?php

namespace App\Services\AI\Inbox;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;

/**
 * Entry point for Inbox AI agent runs.
 */
class InboxAgentOrchestrator
{
    public function __construct(
        private readonly InboxAgentContextBuilder $contextBuilder,
        private readonly InboxAgentLoop $loop,
    ) {}

    public function run(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
    ): InboxAgentResult {
        $context = $this->contextBuilder->build($workspace, $conversation, $message, $setting);

        return $this->loop->run($context);
    }
}
