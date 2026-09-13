<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMembership
{
    public function __construct(
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
        $workspace = $this->workspaceContext->workspace();

        if (! $user || ! $workspace) {
            abort(Response::HTTP_FORBIDDEN, 'Workspace context is required.');
        }

        $isMember = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized for this workspace.');
        }

        return $next($request);
    }
}
