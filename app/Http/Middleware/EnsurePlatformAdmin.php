<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('platform_admin')->check()) {
            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, 'Platform admin authentication required.');
            }

            return redirect()->route('platform.login');
        }

        return app(WorkspaceContext::class)->runBypassingTenantScope(
            'platform-admin-panel',
            fn () => $next($request),
        );
    }
}
