<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ChannelOutboundRetryableException;
use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Jobs\SendWhatsAppMediaMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Media\MediaSecurityValidator;
use App\Services\Observability\OpsMetricsRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppOutboundService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccess,
    ) {}

    /**
     * Queue a plain-text WhatsApp outbound message.
     *
     * Controllers (and AI reply wiring) should call this rather than dispatching
     * SendWhatsAppMessage directly so entitlements and outbound rows stay consistent.
     *
     * @param  string  $phoneNumberId  Meta Graph phone_number_id (not the local PK)
     */
    public function sendText(
        Workspace $workspace,
        string $phoneNumberId,
        string $to,
        string $body,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): WhatsAppOutboundMessage {
        $this->assertWhatsAppEntitlement($workspace);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if (! $phone) {
            throw new \InvalidArgumentException('WhatsApp phone_number_id is not registered for this workspace.');
        }

        $this->featureAccess->consumeUsage($workspace, 'whatsapp_messages', 1, enforce: true);

        $outbound = WhatsAppOutboundMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_phone_number_id' => $phone->id,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'to' => $to,
            'type' => 'text',
            'body' => $body,
            'status' => WhatsAppOutboundMessage::STATUS_QUEUED,
            'attempts' => 0,
            'payload' => [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        ]);

        SendWhatsAppMessage::dispatch(
            phoneNumberId: $phoneNumberId,
            to: $to,
            message: $body,
            outboundMessageId: $outbound->id,
            workspaceId: (int) $workspace->id,
        )->onQueue((string) config('inbox.queues.outbound', 'outbound'));

        return $outbound;
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

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;
        $recipientWaId = $conversation->external_id;

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            $this->markDeliveryFailed($delivery, 'missing_phone_number_id', retryable: false);

            return;
        }

        if (! is_string($recipientWaId) || $recipientWaId === '' || $recipientWaId === 'unknown') {
            $this->markDeliveryFailed($delivery, 'missing_recipient', retryable: false);

            return;
        }

        $workspace = Workspace::withoutGlobalScopes()->find($conversation->workspace_id);
        if (! $workspace) {
            $this->markDeliveryFailed($delivery, 'missing_workspace', retryable: false);

            return;
        }

        try {
            $this->assertWhatsAppEntitlement($workspace);
        } catch (FeatureNotAvailableException|UsageLimitExceededException $exception) {
            $this->markDeliveryFailed($delivery, $exception->getMessage(), retryable: false);

            return;
        }

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if (! $phone) {
            $this->markDeliveryFailed($delivery, 'phone_number_not_registered', retryable: false);

            return;
        }

        $delivery->forceFill([
            'status' => ChannelOutboundDelivery::STATUS_SENDING,
            'attempts' => (int) $delivery->attempts + 1,
        ])->save();

        try {
            $this->featureAccess->consumeUsage($workspace, 'whatsapp_messages', 1, enforce: true);
        } catch (UsageLimitExceededException $exception) {
            $this->markDeliveryFailed($delivery, $exception->getMessage(), retryable: false);

            return;
        }

        $outbound = WhatsAppOutboundMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_phone_number_id' => $phone->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'to' => $recipientWaId,
            'type' => 'text',
            'body' => $body,
            'status' => WhatsAppOutboundMessage::STATUS_SENDING,
            'attempts' => (int) $delivery->attempts,
            'payload' => [
                'messaging_product' => 'whatsapp',
                'to' => $recipientWaId,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        ]);

        $token = config('services.whatsapp.token');
        if (! is_string($token) || $token === '') {
            $this->markWhatsAppOutboundFailed($outbound, 'WhatsApp permanent token is not configured.');
            $this->markDeliveryFailed($delivery, 'missing_token', retryable: false);

            return;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('whatsapp.api_version', 'v20.0'),
            $phoneNumberId,
        );

        $started = microtime(true);

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $recipientWaId,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $responseBody = $response->json();
        if (! is_array($responseBody)) {
            $responseBody = ['raw' => $response->body()];
        }

        if ($response->failed()) {
            $error = (string) (data_get($responseBody, 'error.message') ?: $response->body() ?: 'WhatsApp Graph API error');
            $this->markWhatsAppOutboundFailed($outbound, $error, $responseBody);

            Log::warning('whatsapp.outbound.failed', [
                'http_status' => $response->status(),
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'workspace_id' => $conversation->workspace_id,
                'duration_ms' => $durationMs,
            ]);

            app(OpsMetricsRecorder::class)->increment('outbound.whatsapp.http_error', [
                'http_status' => $response->status(),
            ]);
            app(OpsMetricsRecorder::class)->timing('outbound.whatsapp.latency_ms', $durationMs, [
                'result' => 'error',
            ]);

            $retryable = $response->serverError() || $response->status() === 429;
            $this->markDeliveryFailed($delivery, 'whatsapp_http_'.$response->status(), retryable: $retryable);

            return;
        }

        $providerMessageId = data_get($responseBody, 'messages.0.id');
        $providerMessageId = is_string($providerMessageId) ? trim($providerMessageId) : '';

        $outbound->forceFill([
            'status' => WhatsAppOutboundMessage::STATUS_SENT,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'provider_response' => $responseBody,
            'last_error' => null,
            'sent_at' => now(),
            'failed_at' => null,
        ])->save();

        if ($providerMessageId !== '') {
            $messageMetadata = is_array($message->metadata) ? $message->metadata : [];
            $messageMetadata['whatsapp_message_id'] = $providerMessageId;
            $messageMetadata['channel'] = 'whatsapp';

            $message->forceFill([
                'external_message_id' => $message->external_message_id ?: $providerMessageId,
                'metadata' => $messageMetadata,
            ])->save();
        }

        $delivery->forceFill([
            'status' => ChannelOutboundDelivery::STATUS_SENT,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : $delivery->provider_message_id,
            'last_error' => null,
            'sent_at' => now(),
            'failed_at' => null,
        ])->save();

        app(OpsMetricsRecorder::class)->increment('outbound.whatsapp.success');
        app(OpsMetricsRecorder::class)->timing('outbound.whatsapp.latency_ms', $durationMs, [
            'result' => 'ok',
        ]);
    }

    public function sendStoredAttachment(Conversation $conversation, Message $message, MessageAttachment $attachment): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($conversation->workspace_id);
        if (! $workspace) {
            return;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;
        $recipientWaId = $conversation->external_id;

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($recipientWaId) || $recipientWaId === '') {
            throw new \InvalidArgumentException('WhatsApp outbound media requires phone_number_id and recipient.');
        }

        $this->assertWhatsAppEntitlement($workspace);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('phone_number_id', $phoneNumberId)
            ->firstOrFail();

        $path = app(MediaSecurityValidator::class)->assertSafeStorageKey((string) $attachment->path);
        $disk = Storage::disk($attachment->disk ?: (string) config('media.disk', 'local'));
        $binary = $disk->get($path);
        if (! is_string($binary) || $binary === '') {
            throw new \RuntimeException('WhatsApp outbound media binary missing.');
        }

        $token = config('services.whatsapp.token');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('WhatsApp token missing.');
        }

        $version = (string) config('whatsapp.api_version', 'v20.0');
        $waType = match ($attachment->kind) {
            'image' => 'image',
            'audio' => 'audio',
            'video' => 'video',
            default => 'document',
        };

        $upload = Http::withToken($token)
            ->attach('file', $binary, $attachment->original_name ?: 'media.bin')
            ->post('https://graph.facebook.com/'.$version.'/'.$phoneNumberId.'/media', [
                'messaging_product' => 'whatsapp',
                'type' => $waType,
            ]);

        if ($upload->failed()) {
            throw new \RuntimeException('WhatsApp media upload failed: '.$upload->status());
        }

        $mediaId = trim((string) $upload->json('id'));
        if ($mediaId === '') {
            throw new \RuntimeException('WhatsApp media upload missing id.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientWaId,
            'type' => $waType,
            $waType => ['id' => $mediaId],
        ];

        $this->featureAccess->consumeUsage($workspace, 'whatsapp_messages', 1, enforce: true);

        $outbound = WhatsAppOutboundMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_phone_number_id' => $phone->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'to' => $recipientWaId,
            'type' => $waType,
            'body' => (string) $message->content,
            'status' => WhatsAppOutboundMessage::STATUS_QUEUED,
            'attempts' => 0,
            'payload' => $payload,
        ]);

        SendWhatsAppMediaMessage::dispatch(
            phoneNumberId: $phoneNumberId,
            payload: $payload,
            outboundMessageId: $outbound->id,
            workspaceId: (int) $workspace->id,
        )->onQueue((string) config('inbox.queues.outbound', 'outbound'));
    }

    private function markDeliveryFailed(ChannelOutboundDelivery $delivery, string $reason, bool $retryable): void
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

    /**
     * @param  array<string, mixed>|null  $providerResponse
     */
    private function markWhatsAppOutboundFailed(
        WhatsAppOutboundMessage $outbound,
        string $error,
        ?array $providerResponse = null,
    ): void {
        $outbound->forceFill([
            'status' => WhatsAppOutboundMessage::STATUS_FAILED,
            'last_error' => $error,
            'provider_response' => $providerResponse ?? $outbound->provider_response,
            'failed_at' => now(),
        ])->save();
    }

    private function assertWhatsAppEntitlement(Workspace $workspace): void
    {
        $user = auth()->user();

        if ($user) {
            $this->featureAccess->assertFeature($user, $workspace, 'whatsapp');

            return;
        }

        if (! $this->featureAccess->workspaceHasFeature($workspace, 'whatsapp')) {
            throw new FeatureNotAvailableException(
                feature: 'whatsapp',
                requiredPlan: $this->featureAccess->suggestedPlanForFeature('whatsapp'),
                message: __('ميزة واتساب غير متاحة في باقتك الحالية.'),
            );
        }
    }
}
