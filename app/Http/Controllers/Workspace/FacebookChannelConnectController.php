<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\Meta\Facebook\FacebookConnectService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\ChannelConnect\ChannelOAuthCallbackHandler;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FacebookChannelConnectController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly FacebookConnectService $facebookConnectService,
        private readonly ChannelOAuthCallbackHandler $callbackHandler,
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
        $pageId = $request->filled('page_id') ? (string) $request->input('page_id') : null;

        return $this->callbackHandler->handle(
            $request,
            'facebook_messenger',
            MetaOAuthState::PURPOSE_FACEBOOK,
            fn ($user) => $this->facebookConnectService->completeAuthorization(
                (string) $request->input('code', ''),
                (string) $request->input('state', ''),
                $user,
                $pageId,
            ),
            'Facebook Page connected successfully',
            'Unable to complete Facebook connection. Please try again.',
        );
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
}
