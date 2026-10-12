<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\Telegram\TelegramConnectService;
use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TelegramChannelConnectController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly TelegramConnectService $connectService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless(
            $user && app(WorkspaceAccess::class)->canManageWhatsAppChannels($user, $workspace),
            403
        );

        $validated = $request->validate([
            'bot_token' => ['required', 'string', 'max:255'],
        ]);

        try {
            $connection = $this->connectService->connectBotToken(
                $workspace,
                $user,
                $validated['bot_token'],
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('workspace.channels.index')
                ->with('error', $exception->getMessage());
        }

        $label = $connection->bot_username ? '@'.$connection->bot_username : 'Telegram bot';

        return redirect()
            ->route('workspace.channels.index')
            ->with('success', 'Telegram connected ('.$label.'). Webhook registered.');
    }
}
