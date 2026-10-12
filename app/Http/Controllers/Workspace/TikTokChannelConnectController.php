<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\TikTok\TikTokConnectService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\ChannelConnect\ChannelOAuthCallbackHandler;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TikTokChannelConnectController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly TikTokConnectService $connectService,
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
        return $this->callbackHandler->handle(
            $request,
            'tiktok',
            MetaOAuthState::PURPOSE_TIKTOK,
            fn ($user) => $this->connectService->completeAuthorization(
                (string) $request->input('code', ''),
                (string) $request->input('state', ''),
                $user,
            ),
            'TikTok connected successfully',
            'Unable to complete TikTok connection.',
        );
    }
}
