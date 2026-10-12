<?php

namespace App\Services\Meta\Instagram;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Meta\MetaOAuthState;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramConnectService
{
    private const INSTAGRAM_GRAPH_HOST = 'graph.instagram.com';

    public function __construct(
        private readonly MetaOAuthState $oauthState,
    ) {}

    /**
     * @return array{authorization_url: string, state: string}
     */
    public function beginAuthorizationUrl(Workspace $workspace, User $user, string $returnTo = MetaOAuthState::RETURN_WEB): array
    {
        $appId = $this->appId();
        $scopes = $this->configuredScopes();
        if ($scopes === []) {
            throw new RuntimeException('Instagram OAuth scopes are not configured. Set META_INSTAGRAM_OAUTH_SCOPES in the environment.');
        }

        $issued = $this->oauthState->issue((int) $workspace->id, (int) $user->id, MetaOAuthState::PURPOSE_INSTAGRAM, $returnTo);

        $query = http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $issued['state'],
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => 'https://www.instagram.com/oauth/authorize?'.$query,
            'state' => $issued['state'],
        ];
    }

    public function completeAuthorization(string $code, string $state, User $authenticatedUser): WorkspaceMetaConnection
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Meta did not return an authorization code.');
        }

        $statePayload = $this->oauthState->verify($state);

        if ((int) $authenticatedUser->id !== (int) $statePayload['user_id']) {
            throw new RuntimeException('OAuth callback user does not match the connection initiator.');
        }

        $workspace = Workspace::query()->find($statePayload['workspace_id']);
        if (! $workspace) {
            throw new RuntimeException('Workspace for this OAuth flow was not found.');
        }

        if (! $this->userBelongsToWorkspace($authenticatedUser, $workspace)) {
            throw new RuntimeException('You are not allowed to connect channels for this workspace.');
        }

        $codeExchange = $this->exchangeCodeForShortLivedToken($code);
        $longLived = $this->exchangeForLongLivedInstagramUserToken($codeExchange['access_token']);
        $instagramProfile = $this->resolveInstagramProfile($longLived['access_token']);

        $grantedScopes = $this->normalizeGrantedScopes($codeExchange['permissions']);

        return app(WorkspaceContext::class)->runFor($workspace, function () use (
            $workspace,
            $authenticatedUser,
            $longLived,
            $instagramProfile,
            $grantedScopes,
            $statePayload
        ): WorkspaceMetaConnection {
            return DB::transaction(function () use (
                $workspace,
                $authenticatedUser,
                $longLived,
                $instagramProfile,
                $grantedScopes,
                $statePayload
            ): WorkspaceMetaConnection {
                $existingForOtherWorkspace = WorkspaceMetaConnection::withoutGlobalScopes()
                    ->where('kind', MetaConnectionKind::INSTAGRAM_USER)
                    ->where('external_id', $instagramProfile['user_id'])
                    ->where('workspace_id', '!=', $workspace->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($existingForOtherWorkspace) {
                    throw new RuntimeException('This Instagram account is already connected to another workspace.');
                }

                /** @var WorkspaceMetaConnection $connection */
                $connection = WorkspaceMetaConnection::withoutGlobalScopes()->updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'kind' => MetaConnectionKind::INSTAGRAM_USER,
                        'external_id' => $instagramProfile['user_id'],
                    ],
                    [
                        'display_name' => $instagramProfile['display_name'],
                        'status' => 'connected',
                        'token_expires_at' => $longLived['expires_at'],
                        'scopes' => $grantedScopes !== [] ? $grantedScopes : $this->configuredScopes(),
                        'connected_by_user_id' => $authenticatedUser->id,
                        'metadata' => [
                            'channel' => 'instagram',
                            'graph_api_version' => $this->graphApiVersion(),
                            'graph_host' => self::INSTAGRAM_GRAPH_HOST,
                            'login_product' => 'instagram_business_login',
                            'instagram_username' => $instagramProfile['username'],
                            'account_type' => $instagramProfile['account_type'],
                            'oauth_purpose' => $statePayload['purpose'],
                            'connected_at' => now()->toISOString(),
                        ],
                    ]
                );

                $connection->storeAccessToken($longLived['access_token']);
                $connection->save();

                $this->markWorkspaceInstagramConnected($workspace);

                return $connection->fresh();
            });
        });
    }

    public function disconnect(Workspace $workspace): void
    {
        WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('kind', MetaConnectionKind::INSTAGRAM_USER)
            ->whereNull('deleted_at')
            ->update(['status' => 'disconnected']);

        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $channels = is_array($settings['channels'] ?? null) ? $settings['channels'] : [];
        $instagram = is_array($channels['instagram'] ?? null) ? $channels['instagram'] : [];
        $instagram['connected'] = false;
        $instagram['disconnected_at'] = now()->toISOString();
        $channels['instagram'] = $instagram;
        $settings['channels'] = $channels;
        $workspace->update(['settings' => $settings]);
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('meta.instagram_oauth_redirect_uri'));
        if ($configured !== '') {
            return $configured;
        }

        return route('workspace.channels.instagram.callback', absolute: true);
    }

    /**
     * @return list<string>
     */
    public function configuredScopes(): array
    {
        $raw = config('meta.instagram_oauth_scopes');
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($scope) => trim((string) $scope),
                $raw
            )));
        }

        $csv = trim((string) $raw);

        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    private function appId(): string
    {
        $appId = trim((string) config('meta.app_id'));
        if ($appId === '') {
            throw new RuntimeException('META_APP_ID is not configured.');
        }

        return $appId;
    }

    private function appSecret(): string
    {
        $secret = trim((string) config('meta.app_secret'));
        if ($secret === '') {
            throw new RuntimeException('META_APP_SECRET is not configured.');
        }

        return $secret;
    }

    private function graphApiVersion(): string
    {
        return trim((string) config('meta.graph_api_version', 'v20.0'));
    }

    /**
     * @return array{access_token: string, permissions: ?string}
     */
    private function exchangeCodeForShortLivedToken(string $code): array
    {
        $redirectUri = $this->redirectUri();

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

        if ($response->failed()) {
            $decodedBody = json_decode($response->body(), true);
            $sanitizedBody = is_array($decodedBody)
                ? $this->redactInstagramOAuthLogPayload($decodedBody)
                : $this->redactSensitiveOAuthStrings($response->body());

            Log::warning('instagram.oauth.token_exchange_failed', [
                'http_status' => $response->status(),
                'response_body' => $sanitizedBody,
                'redirect_uri' => $this->redirectUriForLog($redirectUri),
            ]);

            throw new RuntimeException('Instagram authorization code exchange failed.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Instagram authorization code exchange returned an invalid response.');
        }

        $token = $this->readString($body, 'access_token')
            ?? $this->readNestedString($body, ['data', 0, 'access_token']);

        if ($token === null || $token === '') {
            throw new RuntimeException('Instagram authorization code exchange did not return an access token.');
        }

        $permissions = $this->readString($body, 'permissions')
            ?? $this->readNestedString($body, ['data', 0, 'permissions']);

        return [
            'access_token' => $token,
            'permissions' => $permissions,
        ];
    }

    /**
     * @return array{access_token: string, expires_at: ?Carbon}
     */
    private function exchangeForLongLivedInstagramUserToken(string $shortLivedToken): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->get('https://'.self::INSTAGRAM_GRAPH_HOST.'/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->appSecret(),
                'access_token' => $shortLivedToken,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Instagram long-lived token exchange failed.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException('Instagram long-lived token exchange did not return an access token.');
        }

        $expiresIn = $response->json('expires_in');
        $expiresAt = null;
        if (is_numeric($expiresIn) && (int) $expiresIn > 0) {
            $expiresAt = now()->addSeconds((int) $expiresIn);
        }

        return [
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{
     *     user_id: string,
     *     username: ?string,
     *     display_name: string,
     *     account_type: ?string
     * }
     */
    private function resolveInstagramProfile(string $instagramUserAccessToken): array
    {
        $response = Http::acceptJson()
            ->withToken($instagramUserAccessToken)
            ->timeout(20)
            ->get('https://'.self::INSTAGRAM_GRAPH_HOST.'/'.$this->graphApiVersion().'/me', [
                'fields' => 'user_id,username,name,account_type',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Unable to load Instagram account profile after authorization.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Instagram profile response was invalid.');
        }

        if (isset($payload['data'][0]) && is_array($payload['data'][0])) {
            $payload = $payload['data'][0];
        }

        $userId = trim((string) ($payload['user_id'] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('Instagram profile did not include a user_id.');
        }

        $username = trim((string) ($payload['username'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $accountType = trim((string) ($payload['account_type'] ?? ''));

        return [
            'user_id' => $userId,
            'username' => $username !== '' ? $username : null,
            'display_name' => $username !== '' ? '@'.$username : ($name !== '' ? $name : 'Instagram '.$userId),
            'account_type' => $accountType !== '' ? $accountType : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeGrantedScopes(?string $permissions): array
    {
        if ($permissions === null || trim($permissions) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $permissions) ?: [])));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function readString(array $body, string $key): ?string
    {
        if (! array_key_exists($key, $body)) {
            return null;
        }

        if (! is_scalar($body[$key])) {
            return null;
        }

        $value = trim((string) $body[$key]);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int|string, mixed>  $path
     */
    private function readNestedString(array $body, array $path): ?string
    {
        $current = $body;
        foreach ($path as $segment) {
            if (! is_array($current)) {
                return null;
            }
            if (! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        if (! is_scalar($current)) {
            return null;
        }

        $value = trim((string) $current);

        return $value !== '' ? $value : null;
    }

    private function redirectUriForLog(string $redirectUri): string
    {
        $parts = parse_url(trim($redirectUri));
        if (! is_array($parts)) {
            return '[invalid-uri]';
        }

        $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = isset($parts['host']) ? (string) $parts['host'] : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        if ($host === '') {
            return '[invalid-uri]';
        }

        return $scheme.'://'.$host.($path !== '' ? $path : '/');
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    private function redactInstagramOAuthLogPayload(array $payload): array
    {
        $sensitiveKeys = [
            'access_token',
            'code',
            'authorization_code',
            'client_secret',
            'client_id',
        ];

        $redacted = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redactInstagramOAuthLogPayload($value)
                : $value;
        }

        return $redacted;
    }

    private function redactSensitiveOAuthStrings(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $patterns = [
            '/("access_token"\s*:\s*)"[^"]*"/i' => '$1"[redacted]"',
            '/("client_secret"\s*:\s*)"[^"]*"/i' => '$1"[redacted]"',
            '/("client_id"\s*:\s*)"[^"]*"/i' => '$1"[redacted]"',
            '/("code"\s*:\s*)"[^"]*"/i' => '$1"[redacted]"',
            '/("authorization_code"\s*:\s*)"[^"]*"/i' => '$1"[redacted]"',
        ];

        $redacted = $body;
        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted) ?? $redacted;
        }

        if (strlen($redacted) > 4000) {
            return substr($redacted, 0, 4000).'…[truncated]';
        }

        return $redacted;
    }

    private function userBelongsToWorkspace(User $user, Workspace $workspace): bool
    {
        return $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function markWorkspaceInstagramConnected(Workspace $workspace): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $channels = is_array($settings['channels'] ?? null) ? $settings['channels'] : [];
        $instagram = is_array($channels['instagram'] ?? null) ? $channels['instagram'] : [];
        $instagram['connected'] = true;
        $instagram['connected_at'] = now()->toISOString();
        $channels['instagram'] = $instagram;
        $settings['channels'] = $channels;

        $workspace->update(['settings' => $settings]);
    }
}
