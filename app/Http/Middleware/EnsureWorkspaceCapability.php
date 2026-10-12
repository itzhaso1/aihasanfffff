<?php

namespace App\Http\Middleware;

use App\Services\Worker\WorkerEngagementAuthorization;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceCapability
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkerEngagementAuthorization $workerAuthorization,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capabilityKey): Response
    {
        $workspace = $this->workspaceContext->workspace();
        $user = $request->user();

        if (
            $workspace === null
            || $user === null
            || (
                ! $this->workspaceAccess->isActiveMember($user, $workspace)
                && ! $this->workerAuthorization->workerCanInWorkspace($user, $workspace, $capabilityKey)
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => $this->denialMessage($capabilityKey),
                'code' => 'workspace_capability_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function denialMessage(string $capabilityKey): string
    {
        return match ($capabilityKey) {
            WorkerEngagementAuthorization::EMAIL_VIEW_CAPABILITY => 'ليس لديك صلاحيات الوصول إلى البريد.',
            WorkerEngagementAuthorization::STORIES_VIEW_CAPABILITY => 'ليس لديك صلاحيات الوصول إلى التحديثات.',
            default => 'ليس لديك صلاحيات الوصول إلى هذا القسم.',
        };
    }
}
