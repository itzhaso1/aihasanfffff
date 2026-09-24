<?php

namespace App\Services\TikTok;

use App\Exceptions\ChannelOutboundRetryableException;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WorkspaceTikTokConnection;
use App\Support\Channel\ProviderErrorCategory;
use Illuminate\Support\Facades\Log;

class TikTokOutboundService
{
    public function __construct(
        private readonly TikTokApiClient $apiClient,
    ) {}

    public function deliverTextForMessage(
        Conversation $conversation,
        Message $message,
        ChannelOutboundDelivery $delivery,
    ): void {
        $body = trim((string) $message->content);
        if ($body === '') {
            return;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $conversationId = is_string($metadata['tiktok_conversation_id'] ?? null)
            ? trim((string) $metadata['tiktok_conversation_id'])
            : '';
        $businessOpenId = is_string($metadata['tiktok_business_open_id'] ?? null)
            ? trim((string) $metadata['tiktok_business_open_id'])
            : '';

        if ($conversationId === '' || $businessOpenId === '') {
            $this->markFailed($delivery, ProviderErrorCategory::INVALID_RECIPIENT, retryable: false);

            return;
        }

        $connection = WorkspaceTikTokConnection::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('business_open_id', $businessOpenId)
            ->where('status', WorkspaceTikTokConnection::STATUS_CONNECTED)
            ->first();

        $token = $connection?->accessToken();
        if (! $connection || $token === null || $token === '') {
            $this->markFailed($delivery, ProviderErrorCategory::AUTHENTICATION_FAILED, retryable: false);

            return;
        }

        $delivery->forceFill([
            'status' => ChannelOutboundDelivery::STATUS_SENDING,
            'attempts' => (int) $delivery->attempts + 1,
        ])->save();

        $started = microtime(true);

        try {
            $result = $this->apiClient->sendTextMessage($token, $businessOpenId, $conversationId, $body);
        } catch (\Throwable $exception) {
            Log::warning('tiktok.outbound.failed', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_category' => ProviderErrorCategory::PROVIDER_UNAVAILABLE,
            ]);
            $this->markFailed($delivery, ProviderErrorCategory::PROVIDER_UNAVAILABLE, retryable: true);
            throw new ChannelOutboundRetryableException($exception->getMessage());
        }

        $providerMessageId = trim((string) data_get($result, 'message.message_id', ''));

        $delivery->forceFill([
            'status' => ChannelOutboundDelivery::STATUS_SENT,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : $delivery->provider_message_id,
            'last_error' => null,
            'sent_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    private function markFailed(ChannelOutboundDelivery $delivery, string $reason, bool $retryable): void
    {
        $delivery->forceFill([
            'status' => $retryable ? ChannelOutboundDelivery::STATUS_QUEUED : ChannelOutboundDelivery::STATUS_FAILED,
            'last_error' => $reason,
            'failed_at' => $retryable ? $delivery->failed_at : now(),
        ])->save();

        if ($retryable) {
            throw new ChannelOutboundRetryableException($reason);
        }
    }
}
