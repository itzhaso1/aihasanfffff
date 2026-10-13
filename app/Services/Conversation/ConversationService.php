<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    private const DB_CHANNELS = ['whatsapp', 'web', 'manual', 'instagram', 'facebook_messenger', 'email', 'tiktok', 'telegram'];

    public function __construct(
        private readonly ChannelOutboundService $channelOutbound,
        private readonly ConversationHandoffService $handoff,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Conversation
    {
        $data = $this->normalizeChannelPayload($data);

        return Conversation::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addMessage(Conversation $conversation, array $data, ?User $actor = null): Message
    {
        $message = DB::transaction(function () use ($conversation, $data, $actor): Message {
            $message = $conversation->messages()->create([
                ...$data,
                'user_id' => $data['user_id'] ?? $actor?->id,
            ]);

            $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $metadata['channel_source'] = $metadata['channel_source'] ?? $this->resolveDisplayChannel($conversation);

            $conversationAttributes = [
                'last_message_at' => $message->created_at,
                'metadata' => $metadata,
            ];
            if ($message->direction === 'inbound') {
                $conversationAttributes = $conversation->attributesForInboundCustomerMessage($conversationAttributes);
            }
            $conversation->update($conversationAttributes);

            if ($conversation->customer) {
                $conversation->customer->update([
                    'last_conversation_at' => $message->created_at,
                ]);
            }

            return $message;
        });

        if ($message->direction === 'outbound') {
            $this->channelOutbound->queueOutboundForMessage(
                $conversation->fresh() ?? $conversation,
                $message,
            );
        }

        app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage(
            $conversation->fresh() ?? $conversation,
            $message,
        );

        if ($message->direction === 'inbound' && $message->message_type === 'text') {
            app(ConversationClassificationService::class)->applySignals(
                $conversation->fresh() ?? $conversation,
                (string) $message->content,
            );
        }

        app(InboxRealtimePublisher::class)->publishNewMessage(
            $message,
            $conversation->fresh() ?? $conversation,
            ['provider' => 'web_inbox'],
        );

        return $message;
    }

    public function toggleAi(Conversation $conversation, User $actor, bool $enabled): Conversation
    {
        if ($enabled) {
            return $this->handoff->resumeAi($conversation, $actor);
        }

        return $this->handoff->disableAi($conversation, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeChannelPayload(array $data): array
    {
        $requestedChannel = $this->normalizeChannelName((string) ($data['channel'] ?? 'manual'));
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['channel_source'] = $requestedChannel;
        $metadata['unread_count'] = (int) ($metadata['unread_count'] ?? 0);

        $data['channel'] = in_array($requestedChannel, self::DB_CHANNELS, true) ? $requestedChannel : 'manual';
        $data['metadata'] = $metadata;

        return $data;
    }

    private function normalizeChannelName(string $channel): string
    {
        $normalized = strtolower(trim($channel));

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized !== '' ? $normalized : 'manual',
        };
    }

    private function resolveDisplayChannel(Conversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $source = $metadata['channel_source'] ?? $conversation->channel ?? 'manual';

        return $this->normalizeChannelName((string) $source);
    }
}
