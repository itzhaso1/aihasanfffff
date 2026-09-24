<?php

namespace App\Services\TikTok;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceTikTokConnection;
use App\Support\Meta\MetaOAuthState;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TikTokConnectService
{
    public function __construct(
        private readonly MetaOAuthState $oauthState,
        private readonly TikTokApiClient $apiClient,
    ) {}

    /**
     * @return array{authorization_url: string, state: string}
     */
    public function beginAuthorizationUrl(Workspace $workspace, User $user): array
    {
        $clientKey = (string) config('tiktok.client_key');
        $redirectUri = $this->redirectUri();
        $scopes = config('tiktok.oauth_scopes', []);
        if ($clientKey === '' || $redirectUri === '' || ! is_array($scopes) || $scopes === []) {
            throw new RuntimeException('TikTok OAuth is not configured. Set TIKTOK_CLIENT_KEY, TIKTOK_OAUTH_REDIRECT_URI, and TIKTOK_OAUTH_SCOPES.');
        }

        $issued = $this->oauthState->issue((int) $workspace->id, (int) $user->id, MetaOAuthState::PURPOSE_TIKTOK);

        $query = http_build_query([
            'client_key' => $clientKey,
            'redirect_uri' => $redirectUri,
            'state' => $issued['state'],
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => (string) config('tiktok.oauth_authorize_url', 'https://www.tiktok.com/v2/auth/authorize').'?'.$query,
            'state' => $issued['state'],
        ];
    }

    public function completeAuthorization(string $code, string $state, User $authenticatedUser): WorkspaceTikTokConnection
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('TikTok did not return an authorization code.');
        }

        $statePayload = $this->oauthState->verify($state, MetaOAuthState::PURPOSE_TIKTOK);
        if ((int) $authenticatedUser->id !== (int) $statePayload['user_id']) {
            throw new RuntimeException('OAuth callback user does not match the connection initiator.');
        }

        $workspace = Workspace::query()->find($statePayload['workspace_id']);
        if (! $workspace) {
            throw new RuntimeException('Workspace for this OAuth flow was not found.');
        }

        $tokenData = $this->apiClient->exchangeAuthorizationCode($code, $this->redirectUri());
        $openId = trim((string) ($tokenData['open_id'] ?? ''));
        if ($openId === '') {
            throw new RuntimeException('TikTok token response did not include open_id.');
        }

        $accessToken = (string) ($tokenData['access_token'] ?? '');
        $refreshToken = isset($tokenData['refresh_token']) ? (string) $tokenData['refresh_token'] : null;
        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);

        return app(WorkspaceContext::class)->runFor($workspace, function () use (
            $workspace,
            $authenticatedUser,
            $openId,
            $accessToken,
            $refreshToken,
            $expiresIn,
            $tokenData,
        ): WorkspaceTikTokConnection {
            return DB::transaction(function () use (
                $workspace,
                $authenticatedUser,
                $openId,
                $accessToken,
                $refreshToken,
                $expiresIn,
                $tokenData,
            ): WorkspaceTikTokConnection {
                $other = WorkspaceTikTokConnection::withoutGlobalScopes()
                    ->where('business_open_id', $openId)
                    ->where('workspace_id', '!=', $workspace->id)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($other) {
                    throw new RuntimeException('This TikTok Business Account is already connected to another workspace.');
                }

                $connection = WorkspaceTikTokConnection::withoutGlobalScopes()->firstOrNew([
                    'workspace_id' => $workspace->id,
                    'business_open_id' => $openId,
                ]);

                $connection->fill([
                    'display_name' => is_string($tokenData['display_name'] ?? null) ? (string) $tokenData['display_name'] : 'TikTok Business',
                    'status' => WorkspaceTikTokConnection::STATUS_CONNECTED,
                    'scopes' => is_array($tokenData['scope'] ?? null) ? $tokenData['scope'] : null,
                    'metadata' => ['open_id' => $openId],
                    'connected_by_user_id' => $authenticatedUser->id,
                    'token_expires_at' => $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
                ]);
                $connection->storeTokens($accessToken, $refreshToken);
                $connection->save();

                return $connection;
            });
        });
    }

    private function redirectUri(): string
    {
        return trim((string) config('tiktok.redirect_uri', ''));
    }
}
