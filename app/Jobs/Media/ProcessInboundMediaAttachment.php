<?php

namespace App\Jobs\Media;

use App\Exceptions\Media\MediaDownloadRetryableException;
use App\Models\MessageAttachment;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Media\MediaStorageService;
use App\Services\Media\Provider\FacebookProviderMediaClient;
use App\Services\Media\Provider\InstagramProviderMediaClient;
use App\Services\Media\Provider\TelegramProviderMediaClient;
use App\Services\Media\Provider\WhatsAppProviderMediaClient;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessInboundMediaAttachment implements ShouldBeUnique, ShouldQueue
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
    ) {
        $this->onQueue((string) config('media.queue', 'media'));
    }

    public function uniqueId(): string
    {
        return 'media-inbound:'.$this->workspaceId.':'.$this->attachmentId;
    }

    public function handle(
        MediaStorageService $storage,
        WhatsAppProviderMediaClient $whatsAppMedia,
        InstagramProviderMediaClient $instagramMedia,
        TelegramProviderMediaClient $telegramMedia,
        FacebookProviderMediaClient $facebookMedia,
    ): void {
        $started = microtime(true);

        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($storage, $whatsAppMedia, $instagramMedia, $telegramMedia, $facebookMedia, $started): void {
            $attachment = MessageAttachment::withoutGlobalScopes()
                ->whereKey($this->attachmentId)
                ->where('workspace_id', $this->workspaceId)
                ->first();

            if (! $attachment || $attachment->status === MessageAttachment::STATUS_STORED) {
                return;
            }

            $attachment->forceFill(['status' => MessageAttachment::STATUS_DOWNLOADING])->save();

            $message = $attachment->message()->first();
            if (! $message) {
                $this->markFailed($attachment, 'message_missing');

                return;
            }

            try {
                $result = match ($attachment->provider) {
                    'whatsapp' => $whatsAppMedia->download((string) $attachment->provider_media_id),
                    'instagram' => $instagramMedia->downloadForMessage(
                        $message,
                        (string) data_get($attachment->provider_metadata, 'download_url'),
                    ),
                    'facebook_messenger' => $facebookMedia->downloadForMessage(
                        $message,
                        (string) data_get($attachment->provider_metadata, 'download_url'),
                    ),
                    'telegram' => $telegramMedia->downloadForMessage(
                        $message,
                        (string) $attachment->provider_media_id,
                    ),
                    'tiktok' => throw new \RuntimeException('tiktok_media_download_not_implemented'),
                    default => throw new \RuntimeException('unsupported_provider'),
                };

                $stored = $storage->storeBinary(
                    workspaceId: (int) $message->workspace_id,
                    messageId: (int) $message->id,
                    kind: (string) $attachment->kind,
                    binary: $result->binary,
                    detectedMime: $result->mimeType,
                    originalFilename: $result->originalFilename,
                );

                $attachment->forceFill([
                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                    'checksum' => $stored['checksum'],
                    'status' => MessageAttachment::STATUS_STORED,
                    'provider_metadata' => array_diff_key(
                        is_array($attachment->provider_metadata) ? $attachment->provider_metadata : [],
                        ['download_url' => true],
                    ),
                ])->save();

                if ($message->message_type === 'text' && in_array($attachment->kind, ['image', 'audio', 'video', 'file'], true)) {
                    $message->forceFill(['message_type' => $attachment->kind])->save();
                }

                $conversation = $message->conversation()->first();
                if ($conversation) {
                    app(InboxRealtimePublisher::class)->publishNewMessage(
                        $message->fresh() ?? $message,
                        $conversation,
                        ['provider' => (string) $attachment->provider, 'reason' => 'media_stored'],
                    );
                }

                Log::info('media.inbound.stored', [
                    'workspace_id' => $this->workspaceId,
                    'attachment_id' => $attachment->id,
                    'message_id' => $message->id,
                    'provider' => $attachment->provider,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            } catch (MediaDownloadRetryableException $exception) {
                $attachment->forceFill(['status' => MessageAttachment::STATUS_PENDING])->save();

                Log::warning('media.inbound.retryable', [
                    'workspace_id' => $this->workspaceId,
                    'attachment_id' => $attachment->id,
                    'reason' => $exception->getMessage(),
                ]);

                throw $exception;
            } catch (Throwable $exception) {
                $this->markFailed($attachment, $exception->getMessage());
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $attachment = MessageAttachment::withoutGlobalScopes()
            ->whereKey($this->attachmentId)
            ->where('workspace_id', $this->workspaceId)
            ->first();

        if (! $attachment || $attachment->status === MessageAttachment::STATUS_STORED) {
            return;
        }

        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($attachment, $exception): void {
            $this->markFailed($attachment, $exception?->getMessage() ?: 'media_failed');
        });
    }

    private function markFailed(MessageAttachment $attachment, string $reason): void
    {
        $attachment->forceFill([
            'status' => MessageAttachment::STATUS_FAILED,
            'provider_metadata' => array_merge(
                is_array($attachment->provider_metadata) ? $attachment->provider_metadata : [],
                ['last_error' => $reason],
            ),
        ])->save();
    }
}
