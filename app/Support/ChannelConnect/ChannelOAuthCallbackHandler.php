<?php

namespace App\Support\ChannelConnect;

use App\Models\User;
use App\Services\Meta\Facebook\FacebookPageSelectionRequired;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ChannelOAuthCallbackHandler
{
    public function __construct(
        private readonly MetaOAuthState $oauthState,
    ) {}

    /**
     * @param  callable(User): object  $complete
     */
    public function handle(
        Request $request,
        string $channel,
        string $purpose,
        callable $complete,
        string $successMessage,
        string $genericFailureMessage,
    ): RedirectResponse {
        $state = trim((string) $request->input('state', ''));
        $peeked = $this->peekState($state, $purpose);
        $returnTo = (string) ($peeked['return_to'] ?? MetaOAuthState::RETURN_WEB);
        $isMobile = $returnTo === MetaOAuthState::RETURN_MOBILE;

        if ($request->filled('error')) {
            $message = trim((string) $request->input('error_description', $request->input('error_reason', $request->input('error'))));
            if ($message === '') {
                $message = 'Connection was cancelled or denied.';
            }

            return $this->errorRedirect($request, $channel, $isMobile, $message, $peeked);
        }

        $user = $this->resolveUser($request, $peeked, $isMobile);
        if (! $user) {
            if ($isMobile) {
                return ChannelConnectReturn::redirect($channel, 'error', 'Please sign in to complete the connection.');
            }

            return redirect()
                ->route('login')
                ->with('error', 'Please sign in to complete the connection.');
        }

        try {
            $connection = $complete($user);
            $workspaceId = (int) $connection->workspace_id;
            $request->session()->put('current_workspace_id', $workspaceId);
            $label = trim((string) ($connection->display_name ?? ''));
            $message = $label !== '' ? $successMessage.' ('.$label.').' : $successMessage.'.';

            if ($isMobile) {
                return ChannelConnectReturn::redirect($channel, 'success', $message, [
                    'workspace' => $workspaceId,
                ]);
            }

            return redirect()
                ->route('workspace.channels.index')
                ->with('success', $message);
        } catch (FacebookPageSelectionRequired $exception) {
            if ($isMobile) {
                return ChannelConnectReturn::redirect($channel, 'select_page', null, [
                    'attempt' => $exception->attemptId,
                    'workspace' => (int) ($peeked['workspace_id'] ?? 0),
                ]);
            }

            return redirect()
                ->route('workspace.channels.index')
                ->with('error', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return $this->errorRedirect($request, $channel, $isMobile, $exception->getMessage(), $peeked);
        } catch (Throwable $exception) {
            Log::warning($channel.'.connect.callback_failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorRedirect($request, $channel, $isMobile, $genericFailureMessage, $peeked);
        }
    }

    /**
     * @return array{workspace_id?: int, user_id?: int, purpose?: string, nonce?: string, expires_at?: int, return_to?: string}|null
     */
    private function peekState(string $state, string $purpose): ?array
    {
        if ($state === '') {
            return null;
        }

        try {
            return $this->oauthState->peek($state, $purpose);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $peeked
     */
    private function resolveUser(Request $request, ?array $peeked, bool $isMobile): ?User
    {
        $sessionUser = $request->user();
        if ($sessionUser instanceof User && ! $isMobile) {
            return $sessionUser;
        }

        if ($isMobile && is_array($peeked)) {
            $user = User::query()->find((int) ($peeked['user_id'] ?? 0));

            return $user instanceof User ? $user : null;
        }

        return $sessionUser instanceof User ? $sessionUser : null;
    }

    /**
     * @param  array<string, mixed>|null  $peeked
     */
    private function errorRedirect(
        Request $request,
        string $channel,
        bool $isMobile,
        string $message,
        ?array $peeked,
    ): RedirectResponse {
        if (is_array($peeked) && (int) ($peeked['workspace_id'] ?? 0) > 0) {
            $request->session()->put('current_workspace_id', (int) $peeked['workspace_id']);
        }

        if ($isMobile) {
            return ChannelConnectReturn::redirect($channel, 'error', $message, [
                'workspace' => (int) ($peeked['workspace_id'] ?? 0),
            ]);
        }

        return redirect()
            ->route('workspace.channels.index')
            ->with('error', $message);
    }
}
