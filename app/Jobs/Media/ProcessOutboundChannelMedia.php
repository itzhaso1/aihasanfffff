<?php

namespace App\Jobs\Media;

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Meta\Instagram\InstagramOutboundService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOutboundChannelMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly int $attachmentId,
        public readonly int $workspaceId,
        public readonly int $conversationId,
    ) {
        $this->onQueue((string) config('inbox.queues.outbound', 'outbound'));
    }

    public function uniqueId(): string
    {
        return 'media-outbound:'.$this->workspaceId.':'.$this->attachmentId;
    }

    public function handle(
        WhatsAppOutboundService $whatsAppOutbound,
        InstagramOutboundService $instagramOutbound,
    ): void {
        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($whatsAppOutbound, $instagramOutbound): void {
            $attachment = MessageAttachment::withoutGlobalScopes()
                ->whereKey($this->attachmentId)
                ->where('workspace_id', $this->workspaceId)
                ->first();

            if (! $attachment || ! $attachment->isReadyForDownload()) {
                return;
            }

            $conversation = Conversation::withoutGlobalScopes()
                ->whereKey($this->conversationId)
                ->where('workspace_id', $this->workspaceId)
                ->first();

            $message = $attachment->message()->first();
            if (! $conversation || ! $message) {
                return;
            }

            $channel = app(ChannelOutboundService::class)
                ->resolveOutboundChannel($conversation);

            try {
                match ($channel) {
                    'whatsapp' => $whatsAppOutbound->sendStoredAttachment($conversation, $message, $attachment),
                    'instagram' => $instagramOutbound->sendStoredAttachment($conversation, $message, $attachment),
                    default => Log::debug('media.outbound.channel_unhandled', [
                        'channel' => $channel,
                        'attachment_id' => $attachment->id,
                    ]),
                };
            } catch (Throwable $exception) {
                Log::warning('media.outbound.failed', [
                    'workspace_id' => $this->workspaceId,
                    'attachment_id' => $attachment->id,
                    'channel' => $channel,
                    'reason' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        });
    }
}
