<?php

namespace App\Http\Middleware;

use App\Services\Workspace\WorkspaceResolverService;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspaceContext
{
    public function __construct(
        private readonly WorkspaceResolverService $workspaceResolverService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $this->workspaceResolverService->resolveFromRequest($request, $user);

        if ($workspace) {
            $this->workspaceContext->set($workspace);
            $request->attributes->set('workspace', $workspace);
            app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        }

        try {
            return $next($request);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            $this->workspaceContext->clear();
        }
    }
}
