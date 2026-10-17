<?php

namespace App\Jobs;

use App\Services\Inbox\ConversationBulkMessageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessConversationBulkMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $bulkSendId,
        public readonly int $workspaceId,
        public readonly string $content,
    ) {
        $this->onQueue((string) config('inbox.queues.outbound', 'outbound'));
    }

    public function uniqueId(): string
    {
        return 'conversation-bulk:'.$this->workspaceId.':'.$this->bulkSendId;
    }

    public function handle(ConversationBulkMessageService $service): void
    {
        $service->process($this->bulkSendId, $this->workspaceId, $this->content);
    }
}
