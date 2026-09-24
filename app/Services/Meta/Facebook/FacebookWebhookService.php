<?php

namespace App\Services\Meta\Facebook;

use App\Jobs\Customer\ResolveCustomerChannelProfileJob;
use App\Jobs\ProcessIncomingFacebookMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WorkspaceMetaConnection;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Media\InboundMediaCoordinator;
use App\Support\Http\WebhookHeaderSanitizer;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class FacebookWebhookService
{
    public const CONVERSATION_EXTERNAL_ID_PREFIX = 'facebook:';

    public const CUSTOMER_REFERENCE_PREFIX = 'facebook:';

    public function __construct(
        private readonly FacebookWebhookParser $parser,
        private readonly InboundMediaCoordinator $inboundMedia,
        private readonly CustomerChannelIdentityService $channelIdentity,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function processWebhook(array $payload, array $headers): void
    {
        foreach ($this->parser->extractMessagingEvents($payload) as $event) {
            if ($event->isEcho || $event->isDeleted) {
                continue;
            }

            $this->queueInboundEvent($event, $payload, $headers);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function queueInboundEvent(FacebookMessagingEvent $event, array $payload, array $headers): void
    {
        $connections = WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
            ->where('external_id', $event->recipientId)
            ->where('status', 'connected')
            ->whereNull('deleted_at')
            ->get(['id', 'workspace_id']);

        $workspaceIds = $connections
            ->pluck('workspace_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($workspaceIds->count() !== 1) {
            Log::warning('facebook.webhook.page_unresolved', [
                'match_count' => $workspaceIds->count(),
            ]);

            return;
        }

        $workspaceId = (int) $connections->first()->workspace_id;

        try {
            $webhookEvent = app(WorkspaceContext::class)->runForId($workspaceId, function () use ($event, $workspaceId, $headers, $payload): WebhookEvent {
                return WebhookEvent::withoutGlobalScopes()->firstOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'provider' => 'facebook_messenger',
                        'external_event_id' => $event->messageId,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'event_type' => 'message',
                        'idempotency_key' => $event->messageId,
                        'headers' => WebhookHeaderSanitizer::redact($headers),
                        'payload' => $payload,
                        'status' => 'pending',
                    ]
                );
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($webhookEvent->wasRecentlyCreated === false) {
            return;
        }

        ProcessIncomingFacebookMessage::dispatch(
            $workspaceId,
            $this->jobPayload($event),
            $webhookEvent->id,
        )->onQueue((string) config('inbox.queues.inbound', 'inbound'));
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(FacebookMessagingEvent $event): array
    {
        return [
            'sender_id' => $event->senderId,
            'recipient_id' => $event->recipientId,
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
            $senderId = $this->requiredString($messageData['sender_id'] ?? null);
            $recipientId = $this->requiredString($messageData['recipient_id'] ?? null);
            $externalMessageId = $this->requiredString($messageData['message_id'] ?? null);

            $customer = $this->findOrCreateCustomer($workspaceId, $senderId);
            $conversation = $this->findOrCreateConversation($workspaceId, $customer, $senderId, $recipientId);

            $message = $this->persistInboundMessage(
                $workspaceId,
                $customer,
                $conversation,
                $messageData,
                $externalMessageId,
            );

            $identity = $this->channelIdentity->ensureIdentity(
                $workspaceId,
                (int) $customer->id,
                'facebook_messenger',
                $senderId,
            );

            if ($message->wasRecentlyCreated) {
                $descriptors = $this->mediaDescriptorsFromAttachments(
                    is_array($messageData['attachments'] ?? null) ? $messageData['attachments'] : [],
                );
                if ($descriptors !== []) {
                    $this->inboundMedia->registerFromDescriptors($message, 'facebook_messenger', $descriptors);
                }
            }

            if ($this->shouldResolveFacebookProfile($customer, $identity, $message)) {
                ResolveCustomerChannelProfileJob::dispatch((int) $identity->id, $workspaceId);
            }

            $conversationMetadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $conversationMetadata['channel_source'] = 'facebook_messenger';
            $conversationMetadata['facebook_user_id'] = $senderId;
            $conversationMetadata['facebook_page_id'] = $recipientId;
            if ($message->wasRecentlyCreated) {
                $conversationMetadata['unread_count'] = (int) ($conversationMetadata['unread_count'] ?? 0) + 1;
            }

            $conversation->update([
                'customer_id' => $conversation->customer_id ?: $customer->id,
                'channel' => 'facebook_messenger',
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
                    'provider' => 'facebook_messenger',
                    'event_id' => $externalMessageId,
                ],
            );

            return $message;
        });
    }

    private function shouldResolveFacebookProfile(
        Customer $customer,
        CustomerChannelIdentity $identity,
        Message $message,
    ): bool {
        if ($message->wasRecentlyCreated) {
            return true;
        }

        if (trim((string) $identity->display_name) !== '') {
            return false;
        }

        return $this->channelIdentity->customerNameIsFallback($customer->name);
    }

    private function findOrCreateCustomer(int $workspaceId, string $senderId): Customer
    {
        $reference = self::CUSTOMER_REFERENCE_PREFIX.$senderId;

        $existing = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('client_reference', $reference)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Customer::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'name' => 'Facebook User',
                'phone' => $this->customerPhonePlaceholder($senderId),
                'client_reference' => $reference,
                'metadata' => [
                    'channel' => 'facebook_messenger',
                    'facebook_user_id' => $senderId,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            return Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('client_reference', $reference)
                ->firstOrFail();
        }
    }

    private function findOrCreateConversation(
        int $workspaceId,
        Customer $customer,
        string $senderId,
        string $pageId,
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
                'channel' => 'facebook_messenger',
                'external_id' => $externalId,
                'status' => 'open',
                'ai_enabled' => true,
                'last_message_at' => now(),
                'metadata' => [
                    'channel_source' => 'facebook_messenger',
                    'unread_count' => 0,
                    'facebook_user_id' => $senderId,
                    'facebook_page_id' => $pageId,
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

        $messageType = is_string($messageData['message_type'] ?? null)
            ? (string) $messageData['message_type']
            : 'text';
        if (! in_array($messageType, ['text', 'image', 'file', 'system'], true)) {
            $messageType = 'text';
        }

        $metadata = is_array($messageData['metadata'] ?? null) ? $messageData['metadata'] : [];
        $metadata['channel'] = 'facebook_messenger';

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

    public static function facebookUserIdFromConversation(Conversation $conversation): ?string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $fromMetadata = $metadata['facebook_user_id'] ?? null;
        if (is_string($fromMetadata) && trim($fromMetadata) !== '') {
            return trim($fromMetadata);
        }

        $externalId = trim((string) $conversation->external_id);
        if (str_starts_with($externalId, self::CONVERSATION_EXTERNAL_ID_PREFIX)) {
            $psid = substr($externalId, strlen(self::CONVERSATION_EXTERNAL_ID_PREFIX));

            return $psid !== '' ? $psid : null;
        }

        return $externalId !== '' ? $externalId : null;
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     * @return list<array<string, mixed>>
     */
    private function mediaDescriptorsFromAttachments(array $attachments): array
    {
        $descriptors = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $providerMediaId = trim((string) ($attachment['provider_media_id'] ?? ''));
            $downloadUrl = trim((string) ($attachment['download_url'] ?? ''));
            if ($providerMediaId === '' || $downloadUrl === '') {
                continue;
            }

            $type = is_string($attachment['type'] ?? null) ? strtolower((string) $attachment['type']) : 'file';
            $kind = match ($type) {
                'image' => 'image',
                'video' => 'video',
                'audio' => 'audio',
                default => 'file',
            };

            $descriptors[] = [
                'kind' => $kind,
                'provider_media_id' => $providerMediaId,
                'download_url' => $downloadUrl,
            ];
        }

        return $descriptors;
    }

    private function customerPhonePlaceholder(string $senderId): string
    {
        if (strlen($senderId) <= 28) {
            return 'fb:'.$senderId;
        }

        return 'fb:'.substr(hash('sha256', $senderId), 0, 28);
    }

    private function requiredString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Facebook inbound payload is missing a required identifier.');
        }

        return trim($value);
    }
}
