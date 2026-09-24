<?php

namespace App\Jobs;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryExtractionOrchestrator;
use App\Services\AI\Inbox\Memory\InboxAgentMemoryPolicy;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractInboxAgentMemoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {
        $this->onQueue((string) config('ai.inbox_memory.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'memory-extract:'.$this->workspaceId.':'.$this->conversationId.':'.$this->messageId;
    }

    public function handle(
        InboxAgentMemoryExtractionOrchestrator $orchestrator,
        InboxAgentMemoryPolicy $policy,
    ): void {
        if (! $policy->enabled()) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $message = Message::withoutGlobalScopes()
            ->whereKey($this->messageId)
            ->where('conversation_id', $this->conversationId)
            ->first();

        if (
            ! $conversation
            || ! $message
            || (int) $conversation->workspace_id !== $this->workspaceId
            || (int) $message->workspace_id !== $this->workspaceId
            || $message->direction !== 'inbound'
        ) {
            return;
        }

        try {
            app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($orchestrator, $conversation, $message): void {
                $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId);
                $setting = AiSetting::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->first() ?? new AiSetting([
                        'workspace_id' => $workspace->id,
                        'provider' => config('ai.default_provider', 'google_ai_studio'),
                    ]);

                $orchestrator->process($workspace, $conversation, $message, $setting);
            });
        } catch (Throwable $exception) {
            Log::warning('inbox.memory.job_failed', [
                'workspace_id' => $this->workspaceId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
