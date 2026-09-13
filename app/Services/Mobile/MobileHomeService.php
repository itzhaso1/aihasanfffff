<?php

namespace App\Services\Mobile;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

class MobileHomeService
{
    public function __construct(
        private readonly ConversationInboxService $conversationInboxService,
    ) {}

    /**
     * @return array{
     *     unread_conversations:int,
     *     unread_email:int,
     *     unread_notifications:int
     * }
     */
    public function snapshot(User $user, Workspace $workspace): array
    {
        return [
            'unread_conversations' => $this->conversationInboxService->unreadConversationCount($user, $workspace),
            'unread_email' => $this->unreadEmailCount(),
            'unread_notifications' => $this->unreadNotificationsCount($user, $workspace),
        ];
    }

    private function unreadEmailCount(): int
    {
        if (! Schema::hasTable('email_messages') || ! Schema::hasColumn('email_messages', 'read_at')) {
            return 0;
        }

        return (int) EmailMessage::query()
            ->where('type', 'inbound')
            ->whereNull('read_at')
            ->count();
    }

    private function unreadNotificationsCount(User $user, Workspace $workspace): int
    {
        if (! Schema::hasTable('notifications')) {
            return 0;
        }

        return (int) $user->unreadNotifications()
            ->when(
                Schema::hasColumn('notifications', 'workspace_id'),
                fn ($query) => $query->where('workspace_id', $workspace->id),
            )
            ->count();
    }
}
