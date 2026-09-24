<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\TikTok\TikTokConnectService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TikTokChannelConnectController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly TikTokConnectService $connectService,
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
            $begin = $this->connectService->beginAuthorizationUrl($workspace, $user);
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
            return redirect()->route('login')->with('error', 'Please sign in to complete TikTok connection.');
        }

        if ($request->filled('error')) {
            $this->restoreWorkspaceSessionFromState($request);

            return redirect()
                ->route('workspace.channels.index')
                ->with('error', (string) $request->input('error_description', $request->input('error')));
        }

        try {
            $connection = $this->connectService->completeAuthorization(
                (string) $request->input('code', ''),
                (string) $request->input('state', ''),
                $user,
            );
            $request->session()->put('current_workspace_id', (int) $connection->workspace_id);
        } catch (RuntimeException $exception) {
            $this->restoreWorkspaceSessionFromState($request);

            return redirect()->route('workspace.channels.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->restoreWorkspaceSessionFromState($request);
            Log::warning('tiktok.connect.callback_failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('workspace.channels.index')->with('error', 'Unable to complete TikTok connection.');
        }

        return redirect()
            ->route('workspace.channels.index')
            ->with('success', 'TikTok connected successfully ('.$connection->display_name.').');
    }

    private function restoreWorkspaceSessionFromState(Request $request): void
    {
        $state = trim((string) $request->input('state', ''));
        if ($state === '') {
            return;
        }

        try {
            $payload = $this->oauthState->verify($state, MetaOAuthState::PURPOSE_TIKTOK);
            $request->session()->put('current_workspace_id', (int) $payload['workspace_id']);
        } catch (RuntimeException) {
            //
        }
    }
}
