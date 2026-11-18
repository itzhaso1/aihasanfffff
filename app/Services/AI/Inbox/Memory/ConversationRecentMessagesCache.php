<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Memory;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use Illuminate\Support\Facades\Cache;

final class ConversationRecentMessagesCache
{
    public const MAX_MESSAGES = 5;

    public const TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly ?AiSafety $safety = null,
    ) {}

    public static function key(int $workspaceId, int $conversationId): string
    {
        return "conversation_context:{$workspaceId}:{$conversationId}";
    }

    public static function lockKey(int $workspaceId, int $conversationId): string
    {
        return "conversation_context_lock:{$workspaceId}:{$conversationId}";
    }

    /**
     * Get the last 5 messages in chronological order (oldest to newest).
     * Automatically rebuilds from the database if the cache misses.
     *
     * @return list<array{
     *     message_id: int,
     *     sender_identity: string,
     *     role: string,
     *     content: string,
     *     message_type: string,
     *     timestamp: ?string
     * }>
     */
    public function get(int $workspaceId, int $conversationId): array
    {
        $key = self::key($workspaceId, $conversationId);
        $cached = Cache::get($key);

        if (is_array($cached) && ! empty($cached)) {
            return array_values($cached);
        }

        return $this->rebuild($workspaceId, $conversationId);
    }

    /**
     * Push a new message into the sliding window cache (max 5 items, oldest to newest).
     * Deduplicates against existing message_id.
     *
     * @return list<array{
     *     message_id: int,
     *     sender_identity: string,
     *     role: string,
     *     content: string,
     *     message_type: string,
     *     timestamp: ?string
     * }>
     */
    public function push(int $workspaceId, int $conversationId, Message $message): array
    {
        if (! $message->exists || (int) $message->id <= 0) {
            return $this->get($workspaceId, $conversationId);
        }

        $lock = Cache::lock(self::lockKey($workspaceId, $conversationId), 10);

        return $lock->get(function () use ($workspaceId, $conversationId, $message): array {
            $current = $this->get($workspaceId, $conversationId);

            // Deduplication: prevent duplicate entries from retries or webhook replay
            foreach ($current as $item) {
                if (($item['message_id'] ?? null) === (int) $message->id) {
                    return $current;
                }
            }

            $newItem = $this->formatMessage($message);
            $current[] = $newItem;

            // Sliding window: keep only the last MAX_MESSAGES
            if (count($current) > self::MAX_MESSAGES) {
                $current = array_slice($current, -self::MAX_MESSAGES);
            }

            Cache::put(self::key($workspaceId, $conversationId), $current, self::TTL_SECONDS);

            return array_values($current);
        }) ?? $this->get($workspaceId, $conversationId);
    }

    /**
     * Rebuild the last 5 messages cache directly from the database (source of truth).
     *
     * @return list<array{
     *     message_id: int,
     *     sender_identity: string,
     *     role: string,
     *     content: string,
     *     message_type: string,
     *     timestamp: ?string
     * }>
     */
    public function rebuild(int $workspaceId, int $conversationId): array
    {
        $messages = Message::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', $conversationId)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where('message_type', '!=', 'system')
            ->orderByDesc('id')
            ->limit(self::MAX_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $formatted = [];
        foreach ($messages as $msg) {
            $formatted[] = $this->formatMessage($msg);
        }

        Cache::put(self::key($workspaceId, $conversationId), $formatted, self::TTL_SECONDS);

        return $formatted;
    }

    /**
     * Invalidate the cache for this conversation.
     */
    public function invalidate(int $workspaceId, int $conversationId): void
    {
        Cache::forget(self::key($workspaceId, $conversationId));
    }

    /**
     * Format a Message model into the canonical cached representation.
     *
     * @return array{
     *     message_id: int,
     *     sender_identity: string,
     *     role: string,
     *     content: string,
     *     message_type: string,
     *     timestamp: ?string
     * }
     */
    public function formatMessage(Message $message): array
    {
        $senderIdentity = $this->determineSenderIdentity($message);
        $role = $this->mapIdentityToRole($senderIdentity);

        $rawContent = (string) ($message->content ?? '');
        $safeContent = $this->safety !== null
            ? $this->safety->redactSecrets($this->safety->truncateInput($rawContent, 1000))
            : mb_substr(trim($rawContent), 0, 1000);

        return [
            'message_id' => (int) $message->id,
            'sender_identity' => $senderIdentity,
            'role' => $role,
            'content' => $safeContent,
            'message_type' => (string) ($message->message_type ?? 'text'),
            'timestamp' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    public function determineSenderIdentity(Message $message): string
    {
        if ($message->direction === 'inbound') {
            return 'customer';
        }

        if ((bool) $message->ai_generated) {
            return 'ai_employee';
        }

        if ($message->worker_engagement_id !== null) {
            return 'human_employee';
        }

        if ($message->user_id !== null) {
            $isOwner = Workspace::withoutGlobalScopes()
                ->whereKey($message->workspace_id)
                ->where('owner_user_id', $message->user_id)
                ->exists();

            if ($isOwner) {
                return 'admin';
            }

            $user = User::withoutGlobalScopes()->find($message->user_id);
            if ($user !== null) {
                $pivotRole = $user->workspaces()
                    ->where('workspaces.id', $message->workspace_id)
                    ->first()
                    ?->pivot
                    ?->membership_role;

                if (in_array($pivotRole, ['owner', 'admin'], true)) {
                    return 'admin';
                }
            }

            return 'human_employee';
        }

        return 'human_employee';
    }

    private function mapIdentityToRole(string $identity): string
    {
        return match ($identity) {
            'customer' => 'customer',
            'ai_employee' => 'ai',
            'human_employee' => 'human',
            'admin' => 'admin',
            default => 'human',
        };
    }
}
