<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Mobile\MobileInboxActor;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbox-only gate. Workspace members pass. An independent worker passes only
 * when an eligible engagement grants inbox.messages.read. This does not make
 * the worker a workspace member and is not used by other mobile routes.
 */
final class AuthorizeMobileInboxWorkspace
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly MobileInboxActor $inboxActor,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $this->workspaceContext->workspace();

        if (! $user || ! $workspace) {
            abort(Response::HTTP_FORBIDDEN, 'Workspace context is required.');
        }

        if (! $this->inboxActor->canAccessInbox($user, $workspace)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized for this workspace.');
        }

        return $next($request);
    }
}
