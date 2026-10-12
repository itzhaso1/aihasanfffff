<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Mobile\MobileInboxActor;
use App\Services\Worker\WorkerEngagementAuthorization;
use Illuminate\Http\Request;

class WorkspaceResolverService
{
    public function __construct(
        private readonly MobileInboxActor $inboxActor,
        private readonly WorkerEngagementAuthorization $workerAuthorization,
    ) {}

    public function resolveFromRequest(Request $request, ?User $user): ?Workspace
    {
        $requestedWorkspace = $request->route('workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace_id')
            ?? $request->query('workspace');

        if ($requestedWorkspace === null && $request->hasSession()) {
            $requestedWorkspace = $request->session()->get('current_workspace_id');
        }

        if ($requestedWorkspace === null) {
            $requestedWorkspace = $user?->currentAccessToken()?->workspace_id;
        }

        if ($requestedWorkspace === null || $user === null) {
            return null;
        }

        $workspace = $user->workspaces()
            ->where(function ($query) use ($requestedWorkspace): void {
                $query
                    ->where('workspaces.id', $requestedWorkspace)
                    ->orWhere('workspaces.uuid', $requestedWorkspace)
                    ->orWhere('workspaces.slug', $requestedWorkspace);
            })
            ->wherePivot('status', 'active')
            ->first();

        if ($workspace instanceof Workspace) {
            if ($request->hasSession()) {
                $request->session()->put('current_workspace_id', $workspace->id);
            }

            return $workspace;
        }

        return $this->resolveWorkerInboxWorkspace($user, $requestedWorkspace);
    }

    private function resolveWorkerInboxWorkspace(User $user, mixed $requestedWorkspace): ?Workspace
    {
        $workspace = Workspace::query()
            ->where(function ($query) use ($requestedWorkspace): void {
                $query
                    ->where('id', $requestedWorkspace)
                    ->orWhere('uuid', $requestedWorkspace)
                    ->orWhere('slug', $requestedWorkspace);
            })
            ->first();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        if ($this->inboxActor->readEngagementIds($user, $workspace) !== []) {
            return $workspace;
        }

        foreach ([
            WorkerEngagementAuthorization::EMAIL_VIEW_CAPABILITY,
            WorkerEngagementAuthorization::STORIES_VIEW_CAPABILITY,
        ] as $capabilityKey) {
            if ($this->workerAuthorization->workerCanInWorkspace($user, $workspace, $capabilityKey)) {
                return $workspace;
            }
        }

        return null;
    }
}
