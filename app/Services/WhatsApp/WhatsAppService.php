<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppPhoneNumber;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Media\InboundMediaCoordinator;
use App\Support\Http\WebhookHeaderSanitizer;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function __construct(
        private readonly WhatsAppInboundMediaParser $mediaParser,
        private readonly InboundMediaCoordinator $inboundMedia,
        private readonly CustomerChannelIdentityService $channelIdentity,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function processWebhook(array $payload, array $headers): void
    {
        $value = $payload['entry'][0]['changes'][0]['value'] ?? null;
        if (! is_array($value)) {
            return;
        }

        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            return;
        }

        $messages = $value['messages'] ?? null;
        if (! is_array($messages)) {
            return;
        }

        foreach ($messages as $messageData) {
            if (! is_array($messageData)) {
                continue;
            }

            $this->queueSingleInboundMessage($phoneNumberId, $messageData, $payload, $headers);
        }
    }

    /**
     * @param  array<string, mixed>  $messageData
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function queueSingleInboundMessage(
        string $phoneNumberId,
        array $messageData,
        array $payload,
        array $headers,
    ): void {
        $eventId = $this->resolveInboundMessageExternalEventId($messageData);
        if ($eventId === null) {
            Log::warning('whatsapp.webhook.message_id_missing', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        $phones = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('phone_number_id', $phoneNumberId)
            ->get(['id', 'workspace_id']);

        $workspaceIds = $phones
            ->pluck('workspace_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($workspaceIds->count() !== 1) {
            Log::warning('whatsapp.webhook.phone_number_unresolved', [
                'match_count' => $workspaceIds->count(),
            ]);

            return;
        }

        $phone = $phones->first();

        try {
            $event = app(WorkspaceContext::class)->runForId((int) $phone->workspace_id, function () use ($eventId, $phone, $headers, $payload): WebhookEvent {
                return WebhookEvent::withoutGlobalScopes()->firstOrCreate(
                    [
                        'workspace_id' => $phone->workspace_id,
                        'provider' => 'whatsapp',
                        'external_event_id' => $eventId,
                    ],
                    [
                        'workspace_id' => $phone->workspace_id,
                        'event_type' => 'message',
                        'idempotency_key' => $eventId,
                        'headers' => WebhookHeaderSanitizer::redact($headers),
                        'payload' => $payload,
                        'status' => 'pending',
                    ]
                );
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($event->wasRecentlyCreated === false) {
            return;
        }

        ProcessIncomingWhatsAppMessage::dispatch(
            $phone->workspace_id,
            $messageData,
            $event->id,
            $phoneNumberId,
            CorrelationContext::id(),
        )->onQueue((string) config('inbox.queues.inbound', 'inbound'));
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    private function resolveInboundMessageExternalEventId(array $messageData): ?string
    {
        $messageId = $messageData['id'] ?? null;
        if (! is_string($messageId)) {
            return null;
        }

        $messageId = trim($messageId);

        return $messageId !== '' ? $messageId : null;
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function storeIncomingMessage(int $workspaceId, array $messageData, ?string $phoneNumberId = null): Message
    {
        return app(WorkspaceContext::class)->runForId($workspaceId, function () use ($workspaceId, $messageData, $phoneNumberId): Message {
            $customerPhone = $messageData['from'] ?? 'unknown';
            $externalMessageId = $messageData['id'] ?? null;
            $parsed = $this->mediaParser->parse($messageData);

            $customer = Customer::withoutGlobalScopes()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'phone' => $customerPhone,
                ],
                [
                    'name' => 'Customer '.$customerPhone,
                    'whatsapp' => $customerPhone,
                ]
            );

            $identity = $this->channelIdentity->ensureIdentity(
                $workspaceId,
                (int) $customer->id,
                'whatsapp',
                (string) $customerPhone,
            );

            $initialMetadata = [
                'channel_source' => 'whatsapp',
                'unread_count' => 0,
            ];
            if (is_string($phoneNumberId) && $phoneNumberId !== '') {
                $initialMetadata['phone_number_id'] = $phoneNumberId;
            }

            $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'external_id' => $customerPhone,
                ],
                [
                    'customer_id' => $customer->id,
                    'channel' => 'whatsapp',
                    'status' => 'open',
                    'ai_enabled' => true,
                    'last_message_at' => now(),
                    'metadata' => $initialMetadata,
                ]
            );

            $message = $this->persistInboundMessage(
                $workspaceId,
                $customer,
                $conversation,
                $parsed['content'],
                $externalMessageId,
                $parsed['message_type'],
                $messageData,
            );

            if ($message->wasRecentlyCreated && $parsed['descriptors'] !== []) {
                $this->inboundMedia->registerFromDescriptors($message, 'whatsapp', $parsed['descriptors']);
            }

            $conversationMetadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $conversationMetadata['channel_source'] = 'whatsapp';
            if ($message->wasRecentlyCreated) {
                $conversationMetadata['unread_count'] = (int) ($conversationMetadata['unread_count'] ?? 0) + 1;
            }
            if (is_string($phoneNumberId) && $phoneNumberId !== '') {
                $conversationMetadata['phone_number_id'] = $phoneNumberId;
            }

            $conversation->update([
                'customer_id' => $conversation->customer_id ?: $customer->id,
                'channel' => 'whatsapp',
                'last_message_at' => $message->created_at,
                'metadata' => $conversationMetadata,
            ]);

            if ($message->wasRecentlyCreated) {
                $customer->update([
                    'last_conversation_at' => $message->created_at,
                ]);
            }

            $conversation = $conversation->fresh() ?? $conversation;
            app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($conversation, $message);
            $conversation = $conversation->fresh() ?? $conversation;

            app(InboxRealtimePublisher::class)->publishNewInboundMessage(
                $message,
                $conversation,
                [
                    'provider' => 'whatsapp',
                    'event_id' => $message->external_message_id,
                ],
            );

            return $message;
        });
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    private function persistInboundMessage(
        int $workspaceId,
        Customer $customer,
        Conversation $conversation,
        mixed $content,
        mixed $externalMessageId,
        string $messageType,
        array $messageData,
    ): Message {
        $externalId = is_string($externalMessageId) && $externalMessageId !== ''
            ? $externalMessageId
            : null;

        if ($externalId !== null) {
            $existing = Message::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('external_message_id', $externalId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => $messageType,
                'content' => $content,
                'external_message_id' => $externalId,
                'metadata' => $messageData,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($externalId === null) {
                throw $exception;
            }

            return Message::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('external_message_id', $externalId)
                ->firstOrFail();
        }
    }
}
