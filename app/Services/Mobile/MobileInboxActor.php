<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Decides whether a mobile Inbox actor is a workspace member or an
 * engagement-scoped independent worker. Membership is never granted here.
 */
final class MobileInboxActor
{
    /** @var array<string, list<int>> */
    private array $readEngagementIds = [];

    public function __construct(
        private readonly WorkerInboxService $workerInbox,
        private readonly WorkerEngagementAuthorization $authorization,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function isMember(User $user, Workspace $workspace): bool
    {
        return $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function readEngagementIds(User $user, Workspace $workspace): array
    {
        $key = $user->id.':'.$workspace->id;

        return $this->readEngagementIds[$key] ??= $this->workerInbox->readEngagementIds($user, $workspace);
    }

    public function canAccessInbox(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            || $this->readEngagementIds($user, $workspace) !== [];
    }

    public function constrain(Builder $query, User $user, Workspace $workspace): void
    {
        if ($this->isMember($user, $workspace)) {
            return;
        }

        if ($this->readEngagementIds($user, $workspace) === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where('conversations.status', '!=', 'archived');
    }

    public function assertReadable(User $user, Conversation $conversation): void
    {
        $workspace = $this->workspaceFor($conversation);

        if ($this->isMember($user, $workspace)) {
            Gate::forUser($user)->authorize('view', $conversation);

            return;
        }

        if (! $this->workerCanRead($user, $workspace, $conversation)) {
            throw $this->missingConversation($conversation);
        }
    }

    public function assertSendable(User $user, Conversation $conversation): void
    {
        $workspace = $this->workspaceFor($conversation);

        if ($this->isMember($user, $workspace)) {
            Gate::forUser($user)->authorize('reply', $conversation);

            return;
        }

        if (! $this->workerCanRead($user, $workspace, $conversation)) {
            throw $this->missingConversation($conversation);
        }

        if (! $this->authorization->workerCanInWorkspace(
            $user,
            $workspace,
            WorkerInboxService::SEND_CAPABILITY,
        )) {
            throw new AuthorizationException('The worker is not authorized for this capability.');
        }
    }

    public function canReply(User $user, Conversation $conversation): bool
    {
        $workspace = $this->workspaceFor($conversation);

        if ($this->isMember($user, $workspace)) {
            return Gate::forUser($user)->allows('reply', $conversation);
        }

        if (! $this->workerCanRead($user, $workspace, $conversation)) {
            return false;
        }

        return $this->authorization->workerCanInWorkspace(
            $user,
            $workspace,
            WorkerInboxService::SEND_CAPABILITY,
        );
    }

    public function sendsAsWorker(User $user, Conversation $conversation): bool
    {
        $workspace = $this->workspaceFor($conversation);

        if ($this->isMember($user, $workspace)) {
            return false;
        }

        return $this->canReply($user, $conversation);
    }

    private function workerCanRead(User $user, Workspace $workspace, Conversation $conversation): bool
    {
        if ((int) $conversation->workspace_id !== (int) $workspace->id) {
            return false;
        }

        if ($conversation->status === 'archived') {
            return false;
        }

        return $this->readEngagementIds($user, $workspace) !== [];
    }

    private function workspaceFor(Conversation $conversation): Workspace
    {
        $current = $this->workspaceContext->workspace();
        if ($current instanceof Workspace && (int) $current->id === (int) $conversation->workspace_id) {
            return $current;
        }

        return Workspace::query()->findOrFail($conversation->workspace_id);
    }

    private function missingConversation(Conversation $conversation): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->id]);
    }
}
