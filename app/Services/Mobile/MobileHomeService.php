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

    /** @var array<string, bool> */
    private static array $schemaCache = [];

    private function hasTableCached(string $table): bool
    {
        if (app()->runningUnitTests()) {
            return Schema::hasTable($table);
        }

        return self::$schemaCache["table:$table"] ??= Schema::hasTable($table);
    }

    private function hasColumnCached(string $table, string $column): bool
    {
        if (app()->runningUnitTests()) {
            return Schema::hasColumn($table, $column);
        }

        return self::$schemaCache["col:$table.$column"] ??= Schema::hasColumn($table, $column);
    }

    private function unreadEmailCount(): int
    {
        if (! $this->hasTableCached('email_messages') || ! $this->hasColumnCached('email_messages', 'read_at')) {
            return 0;
        }

        return (int) EmailMessage::query()
            ->where('type', 'inbound')
            ->whereNull('read_at')
            ->count();
    }

    private function unreadNotificationsCount(User $user, Workspace $workspace): int
    {
        if (! $this->hasTableCached('notifications')) {
            return 0;
        }

        return (int) $user->unreadNotifications()
            ->when(
                $this->hasColumnCached('notifications', 'workspace_id'),
                fn ($query) => $query->where('workspace_id', $workspace->id),
            )
            ->count();
    }
}
