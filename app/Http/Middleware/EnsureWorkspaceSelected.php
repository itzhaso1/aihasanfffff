<?php

namespace App\Http\Middleware;

use App\Services\Workspace\WorkspaceResolverService;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceSelected
{
    public function __construct(
        private readonly WorkspaceResolverService $workspaceResolverService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $workspace = $this->workspaceResolverService->resolveFromRequest($request, $request->user());

        if (! $workspace) {
            return redirect()->route('workspace.choose');
        }

        $this->workspaceContext->set($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $request->attributes->set('workspace', $workspace);

        try {
            return $next($request);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            $this->workspaceContext->clear();
        }
    }
}
