<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Events\Realtime\EmployeeJoinedConversation;
use App\Events\Realtime\EmployeeLeftConversation;
use App\Events\Realtime\EmployeePresenceUpdated;
use App\Events\Realtime\TypingActivity;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class ConversationPresenceService
{
    public const STATE_VIEWING = 'viewing';

    public const STATE_TYPING = 'typing';

    public const STATE_SENDING = 'sending';

    public const STATE_CONNECTED = 'connected';

    public const STATE_DISCONNECTED = 'disconnected';

    public const PRESENCE_TTL_SECONDS = 35;

    public const TYPING_TTL_SECONDS = 6;

    /**
     * Register or update an employee's presence inside a conversation.
     *
     * @return array<string, mixed>
     */
    public function join(Conversation $conversation, User $user, string $state = self::STATE_VIEWING): array
    {
        return $this->ping($conversation, $user, $state, isNewJoin: true);
    }

    /**
     * Heartbeat / update presence for an employee.
     *
     * @return array<string, mixed>
     */
    public function ping(
        Conversation $conversation,
        User $user,
        string $state = self::STATE_VIEWING,
        bool $isNewJoin = false,
    ): array {
        $presence = $this->rawPresence($conversation);
        $now = now()->timestamp;
        $userId = (string) $user->id;

        $wasPresent = isset($presence[$userId]) && ($now - (int) ($presence[$userId]['last_seen_at'] ?? 0)) < self::PRESENCE_TTL_SECONDS;

        $employeeData = [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url ?? null,
            'initials' => mb_substr($user->name, 0, 1),
            'role' => $this->determineRole($conversation, $user),
            'state' => $state,
            'is_assigned' => (int) $conversation->assigned_user_id === (int) $user->id,
            'last_seen_at' => $now,
            'joined_at' => $presence[$userId]['joined_at'] ?? now()->toIso8601String(),
        ];

        $presence[$userId] = $employeeData;
        $activePresence = $this->pruneExpiredPresence($conversation, $presence);

        $this->savePresence($conversation, $activePresence);

        if (! $wasPresent || $isNewJoin) {
            try {
                event(new EmployeeJoinedConversation(
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    employee: $employeeData,
                    presentCount: count($activePresence),
                    presentEmployees: array_values($activePresence),
                ));
            } catch (\Throwable) {
                // Best effort
            }
        } elseif (($presence[$userId]['state'] ?? '') !== $state) {
            try {
                event(new EmployeePresenceUpdated(
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    employee: $employeeData,
                    presentEmployees: array_values($activePresence),
                ));
            } catch (\Throwable) {
                // Best effort
            }
        }

        return $this->getConversationState($conversation);
    }

    /**
     * Record an employee leaving the conversation.
     *
     * @return array<string, mixed>
     */
    public function leave(Conversation $conversation, User $user): array
    {
        $presence = $this->rawPresence($conversation);
        $userId = (string) $user->id;

        if (isset($presence[$userId])) {
            $employee = $presence[$userId];
            unset($presence[$userId]);
            $this->savePresence($conversation, $presence);

            // Also remove from typing if they were typing
            $this->removeTypingUser($conversation, $userId);

            try {
                event(new EmployeeLeftConversation(
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    userId: (int) $user->id,
                    employeeName: $user->name,
                    presentCount: count($presence),
                    presentEmployees: array_values($presence),
                ));
            } catch (\Throwable) {
                // Best effort
            }
        }

        return $this->getConversationState($conversation);
    }

    /**
     * Set typing status for a user or AI employee supporting concurrent typers.
     *
     * @return array<string, mixed>
     */
    public function setTyping(
        Conversation $conversation,
        User|string $userOrId,
        bool $isTyping,
        string $senderType = 'user',
        ?string $senderName = null,
    ): array {
        $now = now()->timestamp;
        $typingMap = $this->rawTyping($conversation);

        $key = $userOrId instanceof User ? (string) $userOrId->id : (string) $userOrId;
        $name = $senderName;
        if (! $name && $userOrId instanceof User) {
            $name = $userOrId->name;
        }

        if ($isTyping) {
            $typingMap[$key] = [
                'id' => $userOrId instanceof User ? (int) $userOrId->id : null,
                'key' => $key,
                'name' => $name ?? 'موظف',
                'sender_type' => $senderType,
                'typed_at' => $now,
            ];
        } else {
            unset($typingMap[$key]);
        }

        // Prune expired typers
        $activeTypers = [];
        foreach ($typingMap as $k => $item) {
            if (($now - (int) ($item['typed_at'] ?? 0)) <= self::TYPING_TTL_SECONDS) {
                $activeTypers[$k] = $item;
            }
        }

        $this->saveTyping($conversation, $activeTypers);

        // Update state in presence if user exists
        if ($userOrId instanceof User) {
            $presence = $this->rawPresence($conversation);
            if (isset($presence[$key])) {
                $presence[$key]['state'] = $isTyping ? self::STATE_TYPING : self::STATE_VIEWING;
                $presence[$key]['last_seen_at'] = $now;
                $this->savePresence($conversation, $presence);
            }
        }

        $typingUsersList = array_values($activeTypers);
        $summary = $this->formatTypingSummary($typingUsersList);

        try {
            event(new TypingActivity(
                workspaceId: (int) $conversation->workspace_id,
                conversationId: (int) $conversation->id,
                senderType: $senderType,
                senderName: $name ?? 'موظف',
                isTyping: ! empty($activeTypers),
                userId: $userOrId instanceof User ? (int) $userOrId->id : null,
                typingUsers: $typingUsersList,
                typingSummary: $summary,
            ));
        } catch (\Throwable) {
            // Best effort
        }

        return [
            'typing_users' => $typingUsersList,
            'typing_summary' => $summary,
        ];
    }

    /**
     * Mark an employee as sending a message.
     */
    public function setSending(Conversation $conversation, User $user): void
    {
        $presence = $this->rawPresence($conversation);
        $userId = (string) $user->id;
        if (isset($presence[$userId])) {
            $presence[$userId]['state'] = self::STATE_SENDING;
            $presence[$userId]['last_seen_at'] = now()->timestamp;
            $this->savePresence($conversation, $presence);

            try {
                event(new EmployeePresenceUpdated(
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    employee: $presence[$userId],
                    presentEmployees: array_values($presence),
                ));
            } catch (\Throwable) {
                // Best effort
            }
        }
    }

    /**
     * Get all currently present employees (non-expired).
     *
     * @return list<array<string, mixed>>
     */
    public function getPresentEmployees(Conversation $conversation): array
    {
        $raw = $this->rawPresence($conversation);
        $active = $this->pruneExpiredPresence($conversation, $raw);
        $this->savePresence($conversation, $active);

        return array_values($active);
    }

    /**
     * Get all currently typing employees / AI.
     *
     * @return list<array<string, mixed>>
     */
    public function getTypingEmployees(Conversation $conversation): array
    {
        $raw = $this->rawTyping($conversation);
        $now = now()->timestamp;
        $active = [];

        foreach ($raw as $k => $item) {
            if (($now - (int) ($item['typed_at'] ?? 0)) <= self::TYPING_TTL_SECONDS) {
                $active[$k] = $item;
            }
        }
        $this->saveTyping($conversation, $active);

        return array_values($active);
    }

    /**
     * Consolidated single source of truth for conversation live state.
     *
     * @return array<string, mixed>
     */
    public function getConversationState(Conversation $conversation): array
    {
        $present = $this->getPresentEmployees($conversation);
        $typing = $this->getTypingEmployees($conversation);
        $typingSummary = $this->formatTypingSummary($typing);

        $assignedUser = $conversation->relationLoaded('assignedUser')
            ? $conversation->assignedUser
            : $conversation->assignedUser()->first();

        return [
            'conversation_id' => (int) $conversation->id,
            'workspace_id' => (int) $conversation->workspace_id,
            'assigned_employee' => $assignedUser ? [
                'id' => (int) $assignedUser->id,
                'name' => $assignedUser->name,
                'avatar_url' => $assignedUser->avatar_url ?? null,
                'is_worker' => $conversation->assigned_worker_engagement_id !== null,
            ] : null,
            'present_employees' => $present,
            'present_count' => count($present),
            'typing_employees' => $typing,
            'typing_summary' => $typingSummary,
            'active_responder' => $conversation->currentResponder(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $typers
     */
    public function formatTypingSummary(array $typers): string
    {
        $count = count($typers);
        if ($count === 0) {
            return '';
        }

        $names = array_map(fn ($t) => (string) ($t['name'] ?? 'موظف'), $typers);

        if ($count === 1) {
            return "{$names[0]} يكتب الآن...";
        }

        if ($count === 2) {
            return "{$names[0]} و{$names[1]} يكتبان الآن...";
        }

        $firstTwo = "{$names[0]}، {$names[1]}";
        $remaining = $count - 2;

        return "{$firstTwo} و{$remaining} آخرون يكتبون الآن...";
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawPresence(Conversation $conversation): array
    {
        $val = Cache::get("conversation:{$conversation->id}:presence_map");

        return is_array($val) ? $val : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $presence
     */
    private function savePresence(Conversation $conversation, array $presence): void
    {
        Cache::put("conversation:{$conversation->id}:presence_map", $presence, now()->addHours(2));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawTyping(Conversation $conversation): array
    {
        $val = Cache::get("conversation:{$conversation->id}:typing_map");

        return is_array($val) ? $val : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $typing
     */
    private function saveTyping(Conversation $conversation, array $typing): void
    {
        Cache::put("conversation:{$conversation->id}:typing_map", $typing, now()->addMinutes(10));

        if (! empty($typing)) {
            $first = reset($typing);
            Cache::put("conversation:{$conversation->id}:active_typing", [
                'user_id' => $first['id'] ?? null,
                'name' => $first['name'] ?? 'موظف',
                'sender_type' => $first['sender_type'] ?? 'user',
                'typed_at' => now()->toIso8601String(),
            ], now()->addSeconds(self::TYPING_TTL_SECONDS));
        } else {
            Cache::forget("conversation:{$conversation->id}:active_typing");
        }
    }

    private function removeTypingUser(Conversation $conversation, string $key): void
    {
        $typing = $this->rawTyping($conversation);
        if (isset($typing[$key])) {
            unset($typing[$key]);
            $this->saveTyping($conversation, $typing);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $presence
     * @return array<string, array<string, mixed>>
     */
    private function pruneExpiredPresence(Conversation $conversation, array $presence): array
    {
        $now = now()->timestamp;
        $active = [];

        foreach ($presence as $userId => $item) {
            $lastSeen = (int) ($item['last_seen_at'] ?? 0);
            if (($now - $lastSeen) <= self::PRESENCE_TTL_SECONDS) {
                $active[$userId] = $item;
            } else {
                // User expired due to missing heartbeat / abrupt disconnect
                try {
                    event(new EmployeeLeftConversation(
                        workspaceId: (int) $conversation->workspace_id,
                        conversationId: (int) $conversation->id,
                        userId: (int) ($item['id'] ?? $userId),
                        employeeName: (string) ($item['name'] ?? 'موظف'),
                        presentCount: max(0, count($presence) - 1),
                        presentEmployees: array_values($active),
                    ));
                } catch (\Throwable) {
                    // Best effort
                }
            }
        }

        return $active;
    }

    private function determineRole(Conversation $conversation, User $user): string
    {
        if ((int) $conversation->assigned_user_id === (int) $user->id) {
            return 'assigned';
        }

        return 'participant';
    }
}
