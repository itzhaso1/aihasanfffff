<?php

namespace App\Services\Telegram;

use App\Jobs\Customer\ResolveCustomerChannelProfileJob;
use App\Jobs\ProcessIncomingTelegramMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WorkspaceTelegramConnection;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Media\InboundMediaCoordinator;
use App\Support\Http\WebhookHeaderSanitizer;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;

class TelegramWebhookService
{
    public const CONVERSATION_EXTERNAL_ID_PREFIX = 'telegram:';

    public const CUSTOMER_REFERENCE_PREFIX = 'telegram:';

    public function __construct(
        private readonly TelegramWebhookParser $parser,
        private readonly InboundMediaCoordinator $inboundMedia,
        private readonly CustomerChannelIdentityService $channelIdentity,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     * @param  array<string, string>  $headers
     */
    public function processUpdate(
        WorkspaceTelegramConnection $connection,
        array $update,
        array $headers,
    ): void {
        $parsed = $this->parser->parse($update);
        if ($parsed === null) {
            return;
        }

        $workspaceId = (int) $connection->workspace_id;
        $externalEventId = $parsed->updateId.':'.$parsed->messageId;

        try {
            $webhookEvent = app(WorkspaceContext::class)->runForId($workspaceId, function () use ($workspaceId, $headers, $update, $externalEventId): WebhookEvent {
                return WebhookEvent::withoutGlobalScopes()->firstOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'provider' => 'telegram',
                        'external_event_id' => $externalEventId,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'event_type' => 'message',
                        'idempotency_key' => $externalEventId,
                        'headers' => WebhookHeaderSanitizer::redact($headers),
                        'payload' => $update,
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

        ProcessIncomingTelegramMessage::dispatch(
            $workspaceId,
            $this->jobPayload($parsed, $connection),
            $webhookEvent->id,
            CorrelationContext::id(),
        )->onQueue((string) config('inbox.queues.inbound', 'inbound'));
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(TelegramInboundUpdate $parsed, WorkspaceTelegramConnection $connection): array
    {
        return [
            'bot_user_id' => $connection->bot_user_id,
            'chat_id' => $parsed->chatId,
            'sender_user_id' => $parsed->senderUserId,
            'message_id' => $parsed->messageId,
            'text' => $parsed->text,
            'message_type' => $parsed->messageType,
            'attachments' => $parsed->attachments,
            'business_connection_id' => $parsed->businessConnectionId,
            'username' => $parsed->username,
            'first_name' => $parsed->firstName,
            'metadata' => $parsed->safeMetadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function storeIncomingMessage(int $workspaceId, array $messageData): Message
    {
        return app(WorkspaceContext::class)->runForId($workspaceId, function () use ($workspaceId, $messageData): Message {
            $senderId = $this->requiredString($messageData['sender_user_id'] ?? null);
            $chatId = $this->requiredString($messageData['chat_id'] ?? null);
            $externalMessageId = 'tg:'.$chatId.':'.$this->requiredString($messageData['message_id'] ?? null);

            $customer = $this->findOrCreateCustomer($workspaceId, $senderId, $messageData);
            $conversation = $this->findOrCreateConversation($workspaceId, $customer, $chatId, $messageData);

            $message = $this->persistInboundMessage($workspaceId, $customer, $conversation, $messageData, $externalMessageId);

            $displayName = is_string($messageData['first_name'] ?? null) ? (string) $messageData['first_name'] : null;
            $username = is_string($messageData['username'] ?? null) ? (string) $messageData['username'] : null;

            $identity = $this->channelIdentity->ensureIdentity(
                $workspaceId,
                (int) $customer->id,
                'telegram',
                $senderId,
                displayName: $displayName,
                username: $username,
            );

            if ($message->wasRecentlyCreated) {
                $descriptors = is_array($messageData['attachments'] ?? null) ? $messageData['attachments'] : [];
                if ($descriptors !== []) {
                    $this->inboundMedia->registerFromDescriptors($message, 'telegram', $descriptors);
                }

                ResolveCustomerChannelProfileJob::dispatch((int) $identity->id, $workspaceId);
            }

            $conversationMetadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $conversationMetadata['channel_source'] = 'telegram';
            $conversationMetadata['telegram_chat_id'] = $chatId;
            $conversationMetadata['telegram_user_id'] = $senderId;
            if (isset($messageData['business_connection_id'])) {
                $conversationMetadata['telegram_business_connection_id'] = $messageData['business_connection_id'];
            }
            if ($message->wasRecentlyCreated) {
                $conversationMetadata['unread_count'] = (int) ($conversationMetadata['unread_count'] ?? 0) + 1;
            }

            $conversationAttributes = [
                'customer_id' => $conversation->customer_id ?: $customer->id,
                'channel' => 'telegram',
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
                ['provider' => 'telegram', 'event_id' => $externalMessageId],
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

        $name = is_string($messageData['first_name'] ?? null) && trim((string) $messageData['first_name']) !== ''
            ? trim((string) $messageData['first_name'])
            : 'Telegram User';

        try {
            return Customer::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'name' => $name,
                'phone' => 'telegram:'.$senderId,
                'client_reference' => $reference,
                'metadata' => [
                    'channel' => 'telegram',
                    'telegram_user_id' => $senderId,
                    'telegram_username' => $messageData['username'] ?? null,
                ],
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
        string $chatId,
        array $messageData,
    ): Conversation {
        $externalId = self::CONVERSATION_EXTERNAL_ID_PREFIX.$chatId;

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
                'channel' => 'telegram',
                'external_id' => $externalId,
                'status' => 'open',
                'ai_enabled' => true,
                'last_message_at' => now(),
                'metadata' => [
                    'channel_source' => 'telegram',
                    'unread_count' => 0,
                    'telegram_chat_id' => $chatId,
                    'telegram_user_id' => $messageData['sender_user_id'] ?? null,
                    'telegram_business_connection_id' => $messageData['business_connection_id'] ?? null,
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
        if (! in_array($messageType, ['text', 'image', 'audio', 'video', 'file', 'system'], true)) {
            $messageType = 'text';
        }

        $metadata = is_array($messageData['metadata'] ?? null) ? $messageData['metadata'] : [];
        $metadata['channel'] = 'telegram';

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
            throw new \InvalidArgumentException('Missing required Telegram message field.');
        }

        return trim($value);
    }
}
