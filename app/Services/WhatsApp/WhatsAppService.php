<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Location\LocationCaptureService;
use App\Services\Location\LocationPayloadNormalizer;
use App\Services\Media\InboundMediaCoordinator;
use App\Support\Http\WebhookHeaderSanitizer;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
        if (is_array($messages)) {
            foreach ($messages as $messageData) {
                if (! is_array($messageData)) {
                    continue;
                }

                $this->queueSingleInboundMessage($phoneNumberId, $messageData, $payload, $headers);
            }
        }

        $statuses = $value['statuses'] ?? null;
        if (is_array($statuses)) {
            $this->processStatusUpdates($phoneNumberId, $statuses);
        }
    }

    /**
     * Handle Meta WhatsApp delivery/read/failed receipt webhooks cleanly per workspace.
     *
     * @param  array<int, mixed>  $statuses
     */
    private function processStatusUpdates(string $phoneNumberId, array $statuses): void
    {
        $phones = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('phone_number_id', $phoneNumberId)
            ->get(['id', 'workspace_id']);

        $workspaceIds = $phones
            ->pluck('workspace_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($workspaceIds->count() !== 1) {
            return;
        }

        $workspaceId = (int) $workspaceIds->first();

        app(WorkspaceContext::class)->runForId($workspaceId, function () use ($workspaceId, $statuses): void {
            $rank = [
                'pending' => 0,
                'sending' => 0,
                'sent' => 1,
                'delivered' => 2,
                'read' => 3,
                'failed' => 4,
            ];

            foreach ($statuses as $statusItem) {
                if (! is_array($statusItem)) {
                    continue;
                }

                $providerMessageId = trim((string) ($statusItem['id'] ?? ''));
                $rawStatus = strtolower(trim((string) ($statusItem['status'] ?? '')));
                if ($providerMessageId === '' || ! in_array($rawStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
                    continue;
                }

                $timestamp = isset($statusItem['timestamp']) && is_numeric($statusItem['timestamp'])
                    ? Carbon::createFromTimestamp((int) $statusItem['timestamp'])
                    : now();
                $errorReason = null;
                if ($rawStatus === 'failed') {
                    $firstError = is_array($statusItem['errors'][0] ?? null) ? $statusItem['errors'][0] : [];
                    $errorReason = trim((string) ($firstError['title'] ?? $firstError['message'] ?? 'whatsapp_delivery_failed'));
                }

                $outbound = WhatsAppOutboundMessage::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('provider_message_id', $providerMessageId)
                    ->first();

                if ($outbound) {
                    $outboundUpdates = [];
                    if ($rawStatus === 'delivered') {
                        $outboundUpdates['status'] = WhatsAppOutboundMessage::STATUS_DELIVERED;
                        $outboundUpdates['delivered_at'] = $outbound->delivered_at ?? $timestamp;
                    } elseif ($rawStatus === 'read') {
                        $outboundUpdates['status'] = WhatsAppOutboundMessage::STATUS_DELIVERED;
                        $outboundUpdates['delivered_at'] = $outbound->delivered_at ?? $timestamp;
                    } elseif ($rawStatus === 'failed') {
                        $outboundUpdates['status'] = WhatsAppOutboundMessage::STATUS_FAILED;
                        $outboundUpdates['failed_at'] = $outbound->failed_at ?? $timestamp;
                        $outboundUpdates['last_error'] = $errorReason;
                    }
                    if ($outboundUpdates !== []) {
                        $outbound->forceFill($outboundUpdates)->save();
                    }
                }

                if ($rawStatus === 'failed') {
                    ChannelOutboundDelivery::withoutGlobalScopes()
                        ->where('workspace_id', $workspaceId)
                        ->where('provider_message_id', $providerMessageId)
                        ->update([
                            'status' => ChannelOutboundDelivery::STATUS_FAILED,
                            'last_error' => $errorReason,
                            'failed_at' => now(),
                        ]);
                }

                $message = Message::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('external_message_id', $providerMessageId)
                    ->first();

                if (! $message && $outbound?->message_id) {
                    $message = Message::withoutGlobalScopes()
                        ->where('workspace_id', $workspaceId)
                        ->find($outbound->message_id);
                }

                if (! $message) {
                    continue;
                }

                app(InboxRealtimePublisher::class)->recordMessageDeliveryStatus(
                    $message,
                    $rawStatus,
                    $errorReason,
                    $timestamp,
                );
            }
        });
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
            if (is_string($phoneNumberId) && $phoneNumberId !== '') {
                $conversationMetadata['phone_number_id'] = $phoneNumberId;
            }

            $conversationAttributes = [
                'customer_id' => $conversation->customer_id ?: $customer->id,
                'channel' => 'whatsapp',
                'last_message_at' => $message->created_at,
                'metadata' => $conversationMetadata,
            ];
            if ($message->wasRecentlyCreated) {
                $conversationAttributes = $conversation->attributesForInboundCustomerMessage($conversationAttributes);
            }
            $conversation->update($conversationAttributes);

            if ($message->wasRecentlyCreated) {
                $customer->update([
                    'last_conversation_at' => $message->created_at,
                ]);
            }

            if ($parsed['message_type'] === 'location') {
                $normalizedLocation = app(LocationPayloadNormalizer::class)->fromWhatsApp(
                    $messageData,
                    (int) $conversation->id,
                    (int) $customer->id,
                );
                if ($normalizedLocation !== null) {
                    app(LocationCaptureService::class)->ingestChannelLocation($conversation, $message, $normalizedLocation);
                    $message = $message->fresh() ?? $message;
                }
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
