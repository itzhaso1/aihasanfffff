<?php

namespace App\Services\Inbox;

use App\Jobs\Push\SendPushNotificationJob;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Models\DevicePushToken;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class CompanyInboxPushDispatcher
{
    public function dispatchForInbound(Message $message, ?Conversation $conversation = null): void
    {
        if ($message->direction !== 'inbound') {
            return;
        }

        $workspaceId = (int) $message->workspace_id;
        if ($workspaceId <= 0) {
            return;
        }

        try {
            $this->dispatch($message, $conversation, $workspaceId);
        } catch (Throwable $exception) {
            Log::warning('inbox.push.dispatch_failed', [
                'workspace_id' => $workspaceId,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function dispatch(Message $message, ?Conversation $conversation, int $workspaceId): void
    {
        $memberIds = Workspace::query()
            ->whereKey($workspaceId)
            ->first()
            ?->users()
            ->wherePivot('status', 'active')
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all() ?? [];

        if ($memberIds === []) {
            return;
        }

        $mutedUserIds = ConversationUserState::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', $message->conversation_id)
            ->whereNotNull('muted_at')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $recipientIds = DevicePushToken::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('revoked_at')
            ->whereIn('user_id', $memberIds)
            ->when($mutedUserIds !== [], fn ($query) => $query->whereNotIn('user_id', $mutedUserIds))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($recipientIds === []) {
            return;
        }

        $title = $this->title($conversation);
        $body = $this->body($message);
        $data = [
            'type' => 'message',
            'conversation_id' => (string) $message->conversation_id,
            'message_id' => (string) $message->id,
            'workspace_id' => (string) $workspaceId,
        ];

        foreach ($recipientIds as $userId) {
            SendPushNotificationJob::dispatch(
                $userId,
                $title,
                $body,
                $data,
                'messages',
                $workspaceId,
            );
        }
    }

    private function title(?Conversation $conversation): string
    {
        $name = $conversation?->customer()->withoutGlobalScopes()->value('name');
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return 'رسالة جديدة';
    }

    private function body(Message $message): string
    {
        $content = trim(strip_tags((string) $message->content));
        if ($content === '') {
            return 'رسالة جديدة';
        }

        return Str::limit($content, 120);
    }
}
