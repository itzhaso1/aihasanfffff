<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\Meta\Instagram\InstagramConnectService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\ChannelConnect\ChannelOAuthCallbackHandler;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InstagramChannelConnectController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly InstagramConnectService $instagramConnectService,
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
            $begin = $this->instagramConnectService->beginAuthorizationUrl($workspace, $user);
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
            'instagram',
            MetaOAuthState::PURPOSE_INSTAGRAM,
            fn ($user) => $this->instagramConnectService->completeAuthorization(
                (string) $request->input('code', ''),
                (string) $request->input('state', ''),
                $user,
            ),
            'Instagram connected successfully',
            'Unable to complete Instagram connection. Please try again.',
        );
    }
}
