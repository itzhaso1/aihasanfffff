<?php

namespace App\Services\Inbox;

use App\Jobs\Push\SendPushNotificationJob;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
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
        $recipientIds = DB::table('device_push_tokens as dpt')
            ->join('workspace_users as wu', function ($join) use ($workspaceId): void {
                $join->on('wu.user_id', '=', 'dpt.user_id')
                    ->where('wu.workspace_id', '=', $workspaceId)
                    ->where('wu.status', '=', 'active');
            })
            ->leftJoin('conversation_user_states as cus', function ($join) use ($workspaceId, $message): void {
                $join->on('cus.user_id', '=', 'dpt.user_id')
                    ->where('cus.workspace_id', '=', $workspaceId)
                    ->where('cus.conversation_id', '=', $message->conversation_id);
            })
            ->where('dpt.workspace_id', '=', $workspaceId)
            ->whereNull('dpt.revoked_at')
            ->whereNull('cus.muted_at')
            ->distinct()
            ->pluck('dpt.user_id')
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
