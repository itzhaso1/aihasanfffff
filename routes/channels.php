<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerInboxService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('workspace.{workspaceId}.conversations', function (User $user, int $workspaceId): bool {
    $workspace = Workspace::withoutGlobalScopes()->find($workspaceId);
    if (! $workspace) {
        return false;
    }

    $isMember = $workspace->users()
        ->where('users.id', $user->id)
        ->where('workspace_users.status', 'active')
        ->exists();

    if ($isMember) {
        return true;
    }

    return app(WorkerEngagementAuthorization::class)->workerCanInWorkspace(
        $user,
        $workspace,
        WorkerInboxService::READ_CAPABILITY
    );
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('workspace.{workspaceId}.conversation.{conversationId}', function (
    User $user,
    int $workspaceId,
    int $conversationId,
): bool {
    $workspace = Workspace::withoutGlobalScopes()->find($workspaceId);
    if (! $workspace) {
        return false;
    }

    $isMember = $workspace->users()
        ->where('users.id', $user->id)
        ->where('workspace_users.status', 'active')
        ->exists();

    $isWorker = false;
    if (! $isMember) {
        $isWorker = app(WorkerEngagementAuthorization::class)->workerCanInWorkspace(
            $user,
            $workspace,
            WorkerInboxService::READ_CAPABILITY
        );
        if (! $isWorker) {
            return false;
        }
    }

    $conversation = Conversation::withoutGlobalScopes()
        ->whereKey($conversationId)
        ->first(['id', 'workspace_id', 'status']);

    if ($conversation === null || (int) $conversation->workspace_id !== (int) $workspaceId) {
        return false;
    }

    if ($isWorker && ! $isMember && $conversation->status === 'archived') {
        return false;
    }

    return true;
}, ['guards' => ['web', 'sanctum']]);
