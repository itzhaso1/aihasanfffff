<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('workspace.{workspaceId}.conversations', function (User $user, int $workspaceId): bool {
    return Workspace::withoutGlobalScopes()
        ->whereKey($workspaceId)
        ->whereHas('users', function ($query) use ($user): void {
            $query->where('users.id', $user->id)->where('workspace_users.status', 'active');
        })
        ->exists();
});

Broadcast::channel('workspace.{workspaceId}.conversation.{conversationId}', function (
    User $user,
    int $workspaceId,
    int $conversationId,
): bool {
    $member = Workspace::withoutGlobalScopes()
        ->whereKey($workspaceId)
        ->whereHas('users', function ($query) use ($user): void {
            $query->where('users.id', $user->id)->where('workspace_users.status', 'active');
        })
        ->exists();

    if (! $member) {
        return false;
    }

    $conversation = Conversation::withoutGlobalScopes()
        ->whereKey($conversationId)
        ->first(['id', 'workspace_id']);

    return $conversation !== null && (int) $conversation->workspace_id === (int) $workspaceId;
});
