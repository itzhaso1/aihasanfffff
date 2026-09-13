<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    private const DB_CHANNELS = ['whatsapp', 'web', 'manual'];

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
        return DB::transaction(function () use ($conversation, $data, $actor): Message {
            $message = $conversation->messages()->create([
                ...$data,
                'user_id' => $data['user_id'] ?? $actor?->id,
            ]);

            $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $unreadCount = (int) ($metadata['unread_count'] ?? 0);

            if ($message->direction === 'inbound') {
                $unreadCount++;
            }

            if (in_array($message->direction, ['outbound', 'internal_note'], true)) {
                $unreadCount = 0;
            }

            $metadata['unread_count'] = max(0, $unreadCount);
            $metadata['channel_source'] = $metadata['channel_source'] ?? $this->resolveDisplayChannel($conversation);

            $conversation->update([
                'last_message_at' => $message->created_at,
                'metadata' => $metadata,
            ]);

            if ($conversation->customer) {
                $conversation->customer->update([
                    'last_conversation_at' => $message->created_at,
                ]);
            }

            return $message;
        });
    }

    public function toggleAi(Conversation $conversation, bool $enabled): Conversation
    {
        $conversation->update(['ai_enabled' => $enabled]);

        return $conversation->refresh();
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
