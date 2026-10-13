<?php

namespace App\Services\TikTok;

use App\Jobs\Customer\ResolveCustomerChannelProfileJob;
use App\Jobs\ProcessIncomingTikTokMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WorkspaceTikTokConnection;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Media\InboundMediaCoordinator;
use App\Support\Http\WebhookHeaderSanitizer;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class TikTokWebhookService
{
    public const CONVERSATION_EXTERNAL_ID_PREFIX = 'tiktok:';

    public const CUSTOMER_REFERENCE_PREFIX = 'tiktok:';

    public function __construct(
        private readonly TikTokWebhookParser $parser,
        private readonly InboundMediaCoordinator $inboundMedia,
        private readonly CustomerChannelIdentityService $channelIdentity,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function processWebhook(array $payload, array $headers): void
    {
        $event = $this->parser->parseEnvelope($payload);
        if ($event === null) {
            return;
        }

        $connections = WorkspaceTikTokConnection::withoutGlobalScopes()
            ->where('business_open_id', $event->businessOpenId)
            ->where('status', WorkspaceTikTokConnection::STATUS_CONNECTED)
            ->whereNull('deleted_at')
            ->get(['id', 'workspace_id']);

        $workspaceIds = $connections->pluck('workspace_id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($workspaceIds->count() !== 1) {
            Log::warning('tiktok.webhook.account_unresolved', ['match_count' => $workspaceIds->count()]);

            return;
        }

        $workspaceId = (int) $connections->first()->workspace_id;

        try {
            $webhookEvent = app(WorkspaceContext::class)->runForId($workspaceId, function () use ($event, $workspaceId, $headers, $payload): WebhookEvent {
                return WebhookEvent::withoutGlobalScopes()->firstOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'provider' => 'tiktok',
                        'external_event_id' => $event->messageId,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'event_type' => 'message',
                        'idempotency_key' => $event->messageId,
                        'headers' => WebhookHeaderSanitizer::redact($headers),
                        'payload' => $payload,
                        'status' => 'pending',
                    ],
                );
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($webhookEvent->wasRecentlyCreated === false) {
            return;
        }

        ProcessIncomingTikTokMessage::dispatch(
            $workspaceId,
            $this->jobPayload($event),
            $webhookEvent->id,
            CorrelationContext::id(),
        )->onQueue((string) config('inbox.queues.inbound', 'inbound'));
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(TikTokMessagingEvent $event): array
    {
        return [
            'business_open_id' => $event->businessOpenId,
            'sender_unique_id' => $event->senderUniqueId,
            'conversation_id' => $event->conversationId,
            'message_id' => $event->messageId,
            'text' => $event->text,
            'message_type' => $event->messageType,
            'timestamp_ms' => $event->timestampMs,
            'attachments' => $event->attachments,
            'metadata' => $event->safeMetadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function storeIncomingMessage(int $workspaceId, array $messageData): Message
    {
        return app(WorkspaceContext::class)->runForId($workspaceId, function () use ($workspaceId, $messageData): Message {
            $senderId = $this->requiredString($messageData['sender_unique_id'] ?? null);
            $conversationProviderId = $this->requiredString($messageData['conversation_id'] ?? null);
            $externalMessageId = $this->requiredString($messageData['message_id'] ?? null);

            $customer = $this->findOrCreateCustomer($workspaceId, $senderId, $messageData);
            $conversation = $this->findOrCreateConversation($workspaceId, $customer, $senderId, $conversationProviderId, $messageData);

            $message = $this->persistInboundMessage($workspaceId, $customer, $conversation, $messageData, $externalMessageId);

            $username = is_string($messageData['metadata']['tiktok_username_from'] ?? null)
                ? (string) $messageData['metadata']['tiktok_username_from']
                : null;

            $identity = $this->channelIdentity->ensureIdentity(
                $workspaceId,
                (int) $customer->id,
                'tiktok',
                $senderId,
                displayName: $username,
                username: $username,
            );

            if ($message->wasRecentlyCreated) {
                $descriptors = is_array($messageData['attachments'] ?? null) ? $messageData['attachments'] : [];
                if ($descriptors !== []) {
                    $this->inboundMedia->registerFromDescriptors($message, 'tiktok', $descriptors);
                }

                ResolveCustomerChannelProfileJob::dispatch((int) $identity->id, $workspaceId);
            }

            $conversationMetadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $conversationMetadata['channel_source'] = 'tiktok';
            $conversationMetadata['tiktok_user_id'] = $senderId;
            $conversationMetadata['tiktok_conversation_id'] = $conversationProviderId;
            $conversationMetadata['tiktok_business_open_id'] = $messageData['business_open_id'] ?? null;
            $conversationAttributes = [
                'customer_id' => $conversation->customer_id ?: $customer->id,
                'channel' => 'tiktok',
                'last_message_at' => $message->created_at,
                'metadata' => $conversationMetadata,
            ];
            if ($message->wasRecentlyCreated) {
                $conversationAttributes = $conversation->attributesForInboundCustomerMessage($conversationAttributes);
            }
            $conversation->update($conversationAttributes);

            if ($message->wasRecentlyCreated) {
                $customer->update(['last_conversation_at' => $message->created_at]);
            }

            app(InboxRealtimePublisher::class)->publishNewInboundMessage(
                $message,
                $conversation->fresh() ?? $conversation,
                ['provider' => 'tiktok', 'event_id' => $externalMessageId],
            );

            return $message;
        });
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    private function findOrCreateCustomer(int $workspaceId, string $senderId, array $messageData): Customer
    {
        $reference = self::CUSTOMER_REFERENCE_PREFIX.$senderId;
        $existing = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('client_reference', $reference)
            ->first();
        if ($existing) {
            return $existing;
        }

        $display = is_string($messageData['metadata']['tiktok_username_from'] ?? null)
            ? (string) $messageData['metadata']['tiktok_username_from']
            : 'TikTok User';

        try {
            return Customer::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'name' => $display,
                'phone' => 'tiktok:'.$senderId,
                'client_reference' => $reference,
                'metadata' => ['channel' => 'tiktok', 'tiktok_unique_id' => $senderId],
            ]);
        } catch (UniqueConstraintViolationException) {
            return Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('client_reference', $reference)
                ->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    private function findOrCreateConversation(
        int $workspaceId,
        Customer $customer,
        string $senderId,
        string $conversationProviderId,
        array $messageData,
    ): Conversation {
        $externalId = self::CONVERSATION_EXTERNAL_ID_PREFIX.$senderId;

        $existing = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('external_id', $externalId)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return Conversation::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'customer_id' => $customer->id,
                'channel' => 'tiktok',
                'external_id' => $externalId,
                'status' => 'open',
                'ai_enabled' => true,
                'last_message_at' => now(),
                'metadata' => [
                    'channel_source' => 'tiktok',
                    'unread_count' => 0,
                    'tiktok_user_id' => $senderId,
                    'tiktok_conversation_id' => $conversationProviderId,
                    'tiktok_business_open_id' => $messageData['business_open_id'] ?? null,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            return Conversation::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('external_id', $externalId)
                ->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    private function persistInboundMessage(
        int $workspaceId,
        Customer $customer,
        Conversation $conversation,
        array $messageData,
        string $externalMessageId,
    ): Message {
        $existing = Message::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('external_message_id', $externalMessageId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $messageType = strtolower((string) ($messageData['message_type'] ?? 'text'));
        if (! in_array($messageType, ['text', 'image', 'video', 'file', 'system'], true)) {
            $messageType = 'text';
        }

        $metadata = is_array($messageData['metadata'] ?? null) ? $messageData['metadata'] : [];
        $metadata['channel'] = 'tiktok';

        try {
            return Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => $messageType,
                'content' => is_string($messageData['text'] ?? null) ? $messageData['text'] : null,
                'external_message_id' => $externalMessageId,
                'metadata' => $metadata,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Message::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('external_message_id', $externalMessageId)
                ->firstOrFail();
        }
    }

    private function requiredString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Missing required TikTok message field.');
        }

        return trim($value);
    }
}
