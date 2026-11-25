<?php

namespace App\Services\Channel\Outbound;

use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Channel\Outbound\Contracts\ChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\FacebookMessengerChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\InstagramChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\TelegramChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\TikTokChannelOutboundDriver;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Media\OutboundChannelMediaService;
use App\Services\Meta\Facebook\FacebookOutboundService;
use App\Services\Meta\Instagram\InstagramOutboundService;
use App\Services\Telegram\TelegramOutboundService;
use App\Services\TikTok\TikTokOutboundService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Support\Observability\OpsLogger;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;

final class ChannelOutboundService
{
    /**
     * @param  list<ChannelOutboundDriver>  $extraDrivers
     */
    public function __construct(
        private readonly Drivers\WhatsAppChannelOutboundDriver $whatsAppDriver,
        private readonly InstagramChannelOutboundDriver $instagramDriver,
        private readonly FacebookMessengerChannelOutboundDriver $facebookDriver,
        private readonly TikTokChannelOutboundDriver $tikTokDriver,
        private readonly TelegramChannelOutboundDriver $telegramDriver,
        array $extraDrivers = [],
    ) {
        $this->drivers = array_merge(
            [$this->whatsAppDriver, $this->instagramDriver, $this->facebookDriver, $this->tikTokDriver, $this->telegramDriver],
            $extraDrivers,
        );
    }

    /** @var list<ChannelOutboundDriver> */
    private array $drivers;

    public function queueOutboundForMessage(Conversation $conversation, Message $message): void
    {
        if ($message->direction !== 'outbound') {
            return;
        }

        if ($message->message_type === 'system') {
            return;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['handoff_acknowledgement'] ?? false) === true) {
            return;
        }

        $hasStoredMedia = MessageAttachment::withoutGlobalScopes()
            ->where('message_id', $message->id)
            ->where('workspace_id', $message->workspace_id)
            ->where('status', MessageAttachment::STATUS_STORED)
            ->exists();

        $hasText = trim((string) $message->content) !== '';

        if (! $hasText && ! $hasStoredMedia) {
            return;
        }

        $channel = $this->resolveOutboundChannel($conversation);
        $driver = $this->driverFor($channel);

        if ($hasText && $driver !== null) {
            OpsLogger::info('PROVIDER_DISPATCH_STARTED', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'provider' => $channel,
            ]);

            try {
                $driver->queueOutbound($conversation, $message);
            } catch (\Throwable $exception) {
                Log::warning('channel.outbound.queue_failed', [
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'channel' => $channel,
                    'reason' => $exception->getMessage(),
                ]);
                OpsLogger::warning('PROVIDER_DISPATCH_FAILED', [
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'provider' => $channel,
                    'retryable' => false,
                    'reason' => $exception->getMessage(),
                ]);

                $this->recordQueueDispatchFailure($conversation, $message, $channel, $exception->getMessage());
            }
        } elseif ($hasText && $driver === null) {
            Log::debug('channel.outbound.no_driver', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'channel' => $channel,
            ]);
        }

        if ($hasStoredMedia) {
            try {
                app(OutboundChannelMediaService::class)->queueStoredAttachments($conversation, $message);
            } catch (\Throwable $exception) {
                Log::warning('channel.outbound.media_queue_failed', [
                    'workspace_id' => $conversation->workspace_id,
                    'message_id' => $message->id,
                    'reason' => $exception->getMessage(),
                ]);
                OpsLogger::warning('PROVIDER_DISPATCH_FAILED', [
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'provider' => $channel,
                    'retryable' => false,
                    'reason' => $exception->getMessage(),
                ]);

                if (! $hasText) {
                    $this->recordQueueDispatchFailure($conversation, $message, $channel, $exception->getMessage());
                }
            }
        }
    }

    /**
     * Repair helper for idempotent retries if a message was persisted before outbound queuing finished.
     */
    public function ensureQueuedForMessage(Conversation $conversation, Message $message): void
    {
        if ($message->direction !== 'outbound' || $message->message_type === 'system') {
            return;
        }

        $channel = $this->resolveOutboundChannel($conversation);
        if (! $this->supportsChannel($channel)) {
            return;
        }

        $existingDelivery = ChannelOutboundDelivery::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('message_id', $message->id)
            ->first();

        if ($existingDelivery !== null) {
            return;
        }

        $this->queueOutboundForMessage($conversation, $message);
    }

    public function retryOutboundForMessage(Conversation $conversation, Message $message): Message
    {
        if ($message->direction !== 'outbound' || $message->message_type === 'system') {
            return $message;
        }

        $currentStatus = strtolower((string) ($message->delivery_status ?? ''));
        if (in_array($currentStatus, ['sent', 'delivered', 'read'], true)) {
            return $message->fresh(['attachments', 'user:id,name', 'customer:id,name']) ?? $message;
        }

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('message_id', $message->id)
            ->first();

        if ($delivery && $delivery->status === ChannelOutboundDelivery::STATUS_SENT) {
            app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus($message, 'sent');

            return $message->fresh(['attachments', 'user:id,name', 'customer:id,name']) ?? $message;
        }

        if ($delivery) {
            $delivery->forceFill([
                'status' => ChannelOutboundDelivery::STATUS_QUEUED,
                'last_error' => null,
                'failed_at' => null,
            ])->save();
        }

        $channel = $this->resolveOutboundChannel($conversation);
        $targetStatus = $this->supportsChannel($channel) ? 'pending' : 'sent';
        app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus(
            $message,
            $targetStatus,
            null,
            null,
            allowRetryReset: true,
        );

        $freshMessage = $message->fresh(['attachments', 'user:id,name', 'customer:id,name']) ?? $message;
        $this->queueOutboundForMessage($conversation, $freshMessage);

        return $message->fresh(['attachments', 'user:id,name', 'customer:id,name']) ?? $freshMessage;
    }

    private function recordQueueDispatchFailure(
        Conversation $conversation,
        Message $message,
        string $channel,
        string $reason,
    ): void {
        ChannelOutboundDelivery::withoutGlobalScopes()->updateOrCreate(
            [
                'workspace_id' => $conversation->workspace_id,
                'message_id' => $message->id,
            ],
            [
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'status' => ChannelOutboundDelivery::STATUS_FAILED,
                'last_error' => $reason,
                'failed_at' => now(),
            ],
        );

        $freshMessage = $message->fresh() ?? $message;
        app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus(
            $freshMessage,
            'failed',
            $reason,
        );
    }

    public function deliverQueuedDelivery(ChannelOutboundDelivery $delivery): void
    {
        if ($delivery->status === ChannelOutboundDelivery::STATUS_SENT) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $delivery->workspace_id, function () use ($delivery): void {
            $message = Message::withoutGlobalScopes()->find($delivery->message_id);
            $conversation = Conversation::withoutGlobalScopes()->find($delivery->conversation_id);

            if (! $message || ! $conversation) {
                return;
            }

            if ((bool) $message->ai_generated && (
                ! $conversation->isOpen()
                || ! (bool) $conversation->ai_enabled
                || ! $conversation->handoffStatusEnum()->allowsAiAutoReply()
                || $conversation->assigned_user_id !== null
            )) {
                $reason = 'تم إلغاء إرسال رد الذكاء الاصطناعي لتولي موظف المحادثة.';
                $delivery->forceFill([
                    'status' => ChannelOutboundDelivery::STATUS_FAILED,
                    'last_error' => $reason,
                    'failed_at' => now(),
                ])->save();
                app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus($message, 'failed', $reason);

                return;
            }

            if ($delivery->channel === 'instagram') {
                app(InstagramOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );
            } elseif ($delivery->channel === 'whatsapp') {
                app(WhatsAppOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );
            } elseif ($delivery->channel === 'facebook_messenger') {
                app(FacebookOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );
            } elseif ($delivery->channel === 'tiktok') {
                app(TikTokOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );
            } elseif ($delivery->channel === 'telegram') {
                app(TelegramOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );
            } else {
                Log::warning('channel.outbound.unhandled_delivery_channel', [
                    'workspace_id' => $delivery->workspace_id,
                    'delivery_id' => $delivery->id,
                    'channel' => $delivery->channel,
                ]);

                return;
            }

            $freshDelivery = $delivery->fresh();
            $freshMessage = $message->fresh();
            if ($freshDelivery && $freshMessage) {
                if ($freshDelivery->status === ChannelOutboundDelivery::STATUS_SENT) {
                    OpsLogger::info('PROVIDER_ACKNOWLEDGED', [
                        'workspace_id' => $delivery->workspace_id,
                        'conversation_id' => $delivery->conversation_id,
                        'message_id' => $delivery->message_id,
                        'provider' => $delivery->channel,
                        'provider_message_id' => $freshDelivery->provider_message_id,
                    ]);
                    app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus($freshMessage, 'sent');
                } elseif ($freshDelivery->status === ChannelOutboundDelivery::STATUS_FAILED) {
                    OpsLogger::warning('PROVIDER_DISPATCH_FAILED', [
                        'workspace_id' => $delivery->workspace_id,
                        'conversation_id' => $delivery->conversation_id,
                        'message_id' => $delivery->message_id,
                        'provider' => $delivery->channel,
                        'retryable' => false,
                        'reason' => $freshDelivery->last_error,
                    ]);
                    app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus($freshMessage, 'failed', $freshDelivery->last_error);
                }
            }
        });
    }

    public function resolveOutboundChannel(Conversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $source = $metadata['channel_source'] ?? $conversation->channel ?? 'manual';
        $normalized = strtolower(trim((string) $source));

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized !== '' ? $normalized : 'manual',
        };
    }

    public function supportsChannel(string $channel): bool
    {
        return $this->driverFor(strtolower(trim($channel))) !== null;
    }

    private function driverFor(string $channel): ?ChannelOutboundDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($channel)) {
                return $driver;
            }
        }

        return null;
    }
}
