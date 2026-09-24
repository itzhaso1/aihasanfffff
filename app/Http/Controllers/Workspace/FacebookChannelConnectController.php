<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\Meta\Facebook\FacebookConnectService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FacebookChannelConnectController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly FacebookConnectService $facebookConnectService,
        private readonly MetaOAuthState $oauthState,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless(
            $user && app(WorkspaceAccess::class)->canManageWhatsAppChannels($user, $workspace),
            403
        );

        try {
            $begin = $this->facebookConnectService->beginAuthorizationUrl($workspace, $user);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('workspace.channels.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($begin['authorization_url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()
                ->route('login')
                ->with('error', 'Please sign in to complete Facebook connection.');
        }

        if ($request->filled('error')) {
            $this->restoreWorkspaceSessionFromState($request);

            $message = trim((string) $request->input('error_description', $request->input('error_reason', $request->input('error'))));

            return redirect()
                ->route('workspace.channels.index')
                ->with('error', $message !== '' ? $message : 'Facebook connection was cancelled or denied.');
        }

        $code = (string) $request->input('code', '');
        $state = (string) $request->input('state', '');
        $pageId = $request->filled('page_id') ? (string) $request->input('page_id') : null;

        try {
            $connection = $this->facebookConnectService->completeAuthorization($code, $state, $user, $pageId);
            $request->session()->put('current_workspace_id', (int) $connection->workspace_id);
        } catch (RuntimeException $exception) {
            $this->restoreWorkspaceSessionFromState($request);

            return redirect()
                ->route('workspace.channels.index')
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->restoreWorkspaceSessionFromState($request);
            Log::warning('facebook.connect.callback_failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('workspace.channels.index')
                ->with('error', 'Unable to complete Facebook connection. Please try again.');
        }

        return redirect()
            ->route('workspace.channels.index')
            ->with('success', 'Facebook Page connected successfully ('.$connection->display_name.').');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless(
            $user && app(WorkspaceAccess::class)->canManageWhatsAppChannels($user, $workspace),
            403
        );

        $this->facebookConnectService->disconnect($workspace);

        return redirect()
            ->route('workspace.channels.index')
            ->with('success', 'Facebook Messenger disconnected.');
    }

    private function restoreWorkspaceSessionFromState(Request $request): void
    {
        $state = trim((string) $request->input('state', ''));
        if ($state === '') {
            return;
        }

        try {
            $payload = $this->oauthState->verify($state, MetaOAuthState::PURPOSE_FACEBOOK);
            $request->session()->put('current_workspace_id', (int) $payload['workspace_id']);
        } catch (RuntimeException) {
            // Ignore invalid state on error redirects.
        }
    }
}
