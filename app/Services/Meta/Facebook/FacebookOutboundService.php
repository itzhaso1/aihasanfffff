<?php

namespace App\Services\Meta\Facebook;

use App\Exceptions\ChannelOutboundRetryableException;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WorkspaceMetaConnection;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookOutboundService
{
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

        $recipientPsid = FacebookWebhookService::facebookUserIdFromConversation($conversation);
        if ($recipientPsid === null) {
            $this->markFailed($delivery, 'missing_recipient', retryable: false);

            return;
        }

        $connection = $this->connectedPage((int) $conversation->workspace_id, $conversation);
        if (! $connection) {
            $this->markFailed($delivery, 'missing_connection', retryable: false);

            return;
        }

        $token = $connection->accessToken();
        if ($token === null || $token === '') {
            $this->markFailed($delivery, 'missing_token', retryable: false);

            return;
        }

        $pageId = trim((string) $connection->external_id);
        $version = $this->graphApiVersion($connection);
        $started = microtime(true);

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->post('https://graph.facebook.com/'.$version.'/'.$pageId.'/messages', [
                'recipient' => ['id' => $recipientPsid],
                'message' => ['text' => $body],
                'messaging_type' => 'RESPONSE',
            ]);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($response->failed()) {
            Log::warning('facebook.outbound.failed', [
                'http_status' => $response->status(),
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'workspace_id' => $conversation->workspace_id,
                'duration_ms' => $durationMs,
            ]);

            app(OpsMetricsRecorder::class)->increment('outbound.facebook.http_error', [
                'http_status' => $response->status(),
            ]);

            $retryable = $response->serverError() || $response->status() === 429;
            $this->markFailed($delivery, 'facebook_http_'.$response->status(), retryable: $retryable);

            return;
        }

        $providerMessageId = trim((string) $response->json('message_id'));
        if ($providerMessageId !== '') {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $metadata['facebook_message_id'] = $providerMessageId;
            $metadata['channel'] = 'facebook_messenger';

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

        app(OpsMetricsRecorder::class)->increment('outbound.facebook.success');
        app(OpsMetricsRecorder::class)->timing('outbound.facebook.latency_ms', $durationMs, [
            'result' => 'ok',
        ]);
    }

    /**
     * @deprecated Use ChannelOutboundService queue path.
     */
    public function sendTextForMessage(Conversation $conversation, Message $message): void
    {
        app(ChannelOutboundService::class)
            ->queueOutboundForMessage($conversation, $message);
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

    private function connectedPage(int $workspaceId, Conversation $conversation): ?WorkspaceMetaConnection
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $pageId = is_string($metadata['facebook_page_id'] ?? null)
            ? trim((string) $metadata['facebook_page_id'])
            : '';

        $query = WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
            ->where('status', 'connected')
            ->whereNull('deleted_at');

        if ($pageId !== '') {
            $matched = (clone $query)->where('external_id', $pageId)->first();
            if ($matched) {
                return $matched;
            }
        }

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : null;
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
