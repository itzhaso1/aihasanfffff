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
use App\Services\Media\OutboundChannelMediaService;
use App\Services\Meta\Facebook\FacebookOutboundService;
use App\Services\Meta\Instagram\InstagramOutboundService;
use App\Services\Telegram\TelegramOutboundService;
use App\Services\TikTok\TikTokOutboundService;
use App\Services\WhatsApp\WhatsAppOutboundService;
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
            }
        }
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

            if ($delivery->channel === 'instagram') {
                app(InstagramOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );

                return;
            }

            if ($delivery->channel === 'whatsapp') {
                app(WhatsAppOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );

                return;
            }

            if ($delivery->channel === 'facebook_messenger') {
                app(FacebookOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );

                return;
            }

            if ($delivery->channel === 'tiktok') {
                app(TikTokOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );

                return;
            }

            if ($delivery->channel === 'telegram') {
                app(TelegramOutboundService::class)->deliverTextForMessage(
                    $conversation,
                    $message,
                    $delivery,
                );

                return;
            }

            Log::warning('channel.outbound.unhandled_delivery_channel', [
                'workspace_id' => $delivery->workspace_id,
                'delivery_id' => $delivery->id,
                'channel' => $delivery->channel,
            ]);
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
