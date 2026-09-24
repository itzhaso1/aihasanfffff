<?php

namespace App\Services\Meta\Instagram;

use App\Exceptions\ChannelOutboundRetryableException;
use App\Exceptions\Media\UnsupportedOutboundMediaException;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WorkspaceMetaConnection;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Mobile\MessageAttachmentService;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramOutboundService
{
    /**
     * @deprecated Prefer ChannelOutboundService queue path. Kept for backward compatibility.
     */
    public function sendTextForMessage(Conversation $conversation, Message $message): void
    {
        if ($conversation->channel !== 'instagram' && ($conversation->metadata['channel_source'] ?? null) !== 'instagram') {
            return;
        }

        app(ChannelOutboundService::class)
            ->queueOutboundForMessage($conversation, $message);
    }

    public function deliverTextForMessage(
        Conversation $conversation,
        Message $message,
        ChannelOutboundDelivery $delivery,
    ): void {
        if ($message->direction !== 'outbound') {
            return;
        }

        $body = trim((string) $message->content);
        if ($body === '') {
            return;
        }

        $delivery->forceFill([
            'status' => ChannelOutboundDelivery::STATUS_SENDING,
            'attempts' => (int) $delivery->attempts + 1,
        ])->save();

        $recipientIgsid = InstagramWebhookService::instagramUserIdFromConversation($conversation);
        if ($recipientIgsid === null) {
            $this->markFailed($delivery, 'missing_recipient', retryable: false);

            return;
        }

        $connection = $this->connectedAccount((int) $conversation->workspace_id, $conversation);
        if (! $connection) {
            $this->markFailed($delivery, 'missing_connection', retryable: false);

            return;
        }

        $token = $connection->accessToken();
        if ($token === null || $token === '') {
            $this->markFailed($delivery, 'missing_token', retryable: false);

            return;
        }

        $version = $this->graphApiVersion($connection);
        $started = microtime(true);

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->post('https://graph.instagram.com/'.$version.'/me/messages', [
                'recipient' => ['id' => $recipientIgsid],
                'message' => ['text' => $body],
            ]);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($response->failed()) {
            Log::warning('instagram.outbound.failed', [
                'http_status' => $response->status(),
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'workspace_id' => $conversation->workspace_id,
                'duration_ms' => $durationMs,
            ]);

            app(OpsMetricsRecorder::class)->increment('outbound.instagram.http_error', [
                'http_status' => $response->status(),
            ]);
            app(OpsMetricsRecorder::class)->timing('outbound.instagram.latency_ms', $durationMs, [
                'result' => 'error',
            ]);

            $retryable = $response->serverError() || $response->status() === 429;
            $this->markFailed(
                $delivery,
                'instagram_http_'.$response->status(),
                retryable: $retryable,
            );

            if ($retryable) {
                throw new ChannelOutboundRetryableException('Instagram outbound HTTP '.$response->status());
            }

            return;
        }

        $providerMessageId = trim((string) $response->json('message_id'));
        if ($providerMessageId !== '') {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $metadata['instagram_message_id'] = $providerMessageId;
            $metadata['channel'] = 'instagram';

            $message->forceFill([
                'external_message_id' => $message->external_message_id ?: $providerMessageId,
                'metadata' => $metadata,
            ])->save();
        }

        $delivery->forceFill([
            'status' => ChannelOutboundDelivery::STATUS_SENT,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : $delivery->provider_message_id,
            'last_error' => null,
            'sent_at' => now(),
            'failed_at' => null,
        ])->save();

        app(OpsMetricsRecorder::class)->increment('outbound.instagram.success');
        app(OpsMetricsRecorder::class)->timing('outbound.instagram.latency_ms', $durationMs, [
            'result' => 'ok',
        ]);
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

    private function connectedAccount(int $workspaceId, Conversation $conversation): ?WorkspaceMetaConnection
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $accountId = is_string($metadata['instagram_account_id'] ?? null)
            ? trim((string) $metadata['instagram_account_id'])
            : '';

        $query = WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('kind', MetaConnectionKind::INSTAGRAM_USER)
            ->where('status', 'connected')
            ->whereNull('deleted_at');

        if ($accountId !== '') {
            $matched = (clone $query)->where('external_id', $accountId)->first();
            if ($matched) {
                return $matched;
            }
        }

        $matches = $query->get();
        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    public function sendStoredAttachment(Conversation $conversation, Message $message, MessageAttachment $attachment): void
    {
        if ($attachment->kind !== 'image') {
            throw new UnsupportedOutboundMediaException('Instagram outbound currently supports image attachments only.');
        }

        if (! $attachment->isReadyForDownload()) {
            throw new \RuntimeException('Attachment is not stored.');
        }

        $recipientIgsid = InstagramWebhookService::instagramUserIdFromConversation($conversation);
        if ($recipientIgsid === null) {
            throw new \RuntimeException('missing_recipient');
        }

        $connection = $this->connectedAccount((int) $conversation->workspace_id, $conversation);
        if (! $connection) {
            throw new \RuntimeException('missing_connection');
        }

        $token = $connection->accessToken();
        if ($token === null || $token === '') {
            throw new \RuntimeException('missing_token');
        }

        $mediaUrl = app(MessageAttachmentService::class)->downloadUrl($attachment, (int) config('media.signed_url_ttl_minutes', 30));

        $version = $this->graphApiVersion($connection);
        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(30)
            ->post('https://graph.instagram.com/'.$version.'/me/messages', [
                'recipient' => ['id' => $recipientIgsid],
                'message' => [
                    'attachment' => [
                        'type' => 'image',
                        'payload' => ['url' => $mediaUrl],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('instagram_outbound_image_'.$response->status());
        }
    }

    private function graphApiVersion(WorkspaceMetaConnection $connection): string
    {
        $metadata = is_array($connection->metadata) ? $connection->metadata : [];
        $fromConnection = trim((string) ($metadata['graph_api_version'] ?? ''));
        if ($fromConnection !== '') {
            return $fromConnection;
        }

        $configured = trim((string) config('meta.graph_api_version', 'v20.0'));

        return $configured !== '' ? $configured : 'v20.0';
    }
}
