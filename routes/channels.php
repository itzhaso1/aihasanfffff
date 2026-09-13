<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('workspace.{workspaceId}.conversations', function (User $user, int $workspaceId): bool {
    return Workspace::query()
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
    $member = Workspace::query()
        ->whereKey($workspaceId)
        ->whereHas('users', function ($query) use ($user): void {
            $query->where('users.id', $user->id)->where('workspace_users.status', 'active');
        })
        ->exists();

    if (! $member) {
        return false;
    }

    return Conversation::withoutGlobalScopes()
        ->whereKey($conversationId)
        ->where('workspace_id', $workspaceId)
        ->exists();
});

Broadcast::channel('workspace.{workspaceId}.pos', function (User $user, int $workspaceId): bool {
    return Workspace::query()
        ->whereKey($workspaceId)
        ->whereHas('users', function ($query) use ($user): void {
            $query->where('users.id', $user->id)->where('workspace_users.status', 'active');
        })
        ->exists();
});
