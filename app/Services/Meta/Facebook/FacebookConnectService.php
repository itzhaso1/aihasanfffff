<?php

namespace App\Services\Meta\Facebook;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Support\ChannelConnect\ChannelConnectAttemptStore;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Meta\MetaOAuthState;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookConnectService
{
    public function __construct(
        private readonly MetaOAuthState $oauthState,
        private readonly ChannelConnectAttemptStore $attemptStore,
    ) {}

    /**
     * @return array{authorization_url: string, state: string}
     */
    public function beginAuthorizationUrl(Workspace $workspace, User $user, string $returnTo = MetaOAuthState::RETURN_WEB): array
    {
        $scopes = $this->configuredScopes();
        if ($scopes === []) {
            throw new RuntimeException('Facebook OAuth scopes are not configured. Set META_FACEBOOK_OAUTH_SCOPES in the environment.');
        }

        $issued = $this->oauthState->issue(
            (int) $workspace->id,
            (int) $user->id,
            MetaOAuthState::PURPOSE_FACEBOOK,
            $returnTo,
        );

        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $issued['state'],
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => 'https://www.facebook.com/'.$this->graphApiVersion().'/dialog/oauth?'.$query,
            'state' => $issued['state'],
        ];
    }

    public function completeAuthorization(string $code, string $state, User $authenticatedUser, ?string $selectedPageId = null): WorkspaceMetaConnection
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Meta did not return an authorization code.');
        }

        $statePayload = $this->oauthState->verify($state, MetaOAuthState::PURPOSE_FACEBOOK);

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

        $userToken = $this->exchangeCodeForUserAccessToken($code);
        $pages = $this->fetchManagedPages($userToken);
        if ($pages === []) {
            throw new RuntimeException('No Facebook Pages with messaging access were found for this account.');
        }

        try {
            $page = $this->selectPage($pages, $selectedPageId);
        } catch (RuntimeException $exception) {
            if (($statePayload['return_to'] ?? MetaOAuthState::RETURN_WEB) === MetaOAuthState::RETURN_MOBILE
                && ($selectedPageId === null || $selectedPageId === '')) {
                $attemptId = $this->attemptStore->issue('facebook_pages', [
                    'workspace_id' => (int) $workspace->id,
                    'user_id' => (int) $authenticatedUser->id,
                    'pages' => $pages,
                ]);

                throw new FacebookPageSelectionRequired($attemptId);
            }

            throw $exception;
        }

        return $this->persistPage($workspace, $authenticatedUser, $page, (string) $statePayload['purpose']);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function pendingPages(string $attemptId, User $user, Workspace $workspace): array
    {
        $payload = $this->assertPageAttempt(
            $this->attemptStore->get('facebook_pages', $attemptId),
            $user,
            $workspace,
        );

        $pages = [];
        foreach ($payload['pages'] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $id = trim((string) ($page['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $pages[] = [
                'id' => $id,
                'name' => trim((string) ($page['name'] ?? 'Facebook Page')),
            ];
        }

        return $pages;
    }

    public function completePageSelection(string $attemptId, string $pageId, User $user, Workspace $workspace): WorkspaceMetaConnection
    {
        $payload = $this->assertPageAttempt(
            $this->attemptStore->pull('facebook_pages', $attemptId),
            $user,
            $workspace,
        );

        /** @var list<array{id: string, name: string, access_token: string}> $pages */
        $pages = [];
        foreach ($payload['pages'] as $page) {
            if (is_array($page)) {
                $pages[] = [
                    'id' => trim((string) ($page['id'] ?? '')),
                    'name' => trim((string) ($page['name'] ?? 'Facebook Page')),
                    'access_token' => trim((string) ($page['access_token'] ?? '')),
                ];
            }
        }

        $page = $this->selectPage($pages, $pageId);

        return $this->persistPage($workspace, $user, $page, MetaOAuthState::PURPOSE_FACEBOOK);
    }

    public function disconnect(Workspace $workspace): void
    {
        WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
            ->whereNull('deleted_at')
            ->update(['status' => 'disconnected']);

        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $channels = is_array($settings['channels'] ?? null) ? $settings['channels'] : [];
        $channels['facebook_messenger'] = [
            'connected' => false,
            'disconnected_at' => now()->toISOString(),
        ];
        $settings['channels'] = $channels;
        $workspace->update(['settings' => $settings]);
    }

    /**
     * @param  array{id: string, name: string, access_token: string}  $page
     */
    private function persistPage(Workspace $workspace, User $authenticatedUser, array $page, string $oauthPurpose): WorkspaceMetaConnection
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use (
            $workspace,
            $authenticatedUser,
            $page,
            $oauthPurpose
        ): WorkspaceMetaConnection {
            return DB::transaction(function () use ($workspace, $authenticatedUser, $page, $oauthPurpose): WorkspaceMetaConnection {
                $pageId = (string) $page['id'];

                $existingForOtherWorkspace = WorkspaceMetaConnection::withoutGlobalScopes()
                    ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
                    ->where('external_id', $pageId)
                    ->where('workspace_id', '!=', $workspace->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($existingForOtherWorkspace) {
                    throw new RuntimeException('This Facebook Page is already connected to another workspace.');
                }

                /** @var WorkspaceMetaConnection $connection */
                $connection = WorkspaceMetaConnection::withoutGlobalScopes()->updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'kind' => MetaConnectionKind::FACEBOOK_PAGE,
                        'external_id' => $pageId,
                    ],
                    [
                        'display_name' => (string) ($page['name'] ?? 'Facebook Page'),
                        'status' => 'connected',
                        'token_expires_at' => null,
                        'scopes' => $this->configuredScopes(),
                        'connected_by_user_id' => $authenticatedUser->id,
                        'metadata' => [
                            'channel' => 'facebook_messenger',
                            'graph_api_version' => $this->graphApiVersion(),
                            'page_name' => $page['name'] ?? null,
                            'oauth_purpose' => $oauthPurpose,
                            'connected_at' => now()->toISOString(),
                        ],
                    ]
                );

                $connection->storeAccessToken((string) $page['access_token']);
                $connection->save();

                $this->markWorkspaceFacebookConnected($workspace, (string) ($page['name'] ?? 'Facebook Page'));

                return $connection->fresh();
            });
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{workspace_id: int, user_id: int, pages: list<mixed>}
     */
    private function assertPageAttempt(?array $payload, User $user, Workspace $workspace): array
    {
        if ($payload === null) {
            throw new RuntimeException('Facebook Page selection expired. Reconnect to try again.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            throw new RuntimeException('OAuth callback user does not match the connection initiator.');
        }

        if ((int) ($payload['workspace_id'] ?? 0) !== (int) $workspace->id) {
            throw new RuntimeException('You are not allowed to connect channels for this workspace.');
        }

        if (! is_array($payload['pages'] ?? null)) {
            throw new RuntimeException('Facebook Page selection expired. Reconnect to try again.');
        }

        return $payload;
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('meta.facebook_oauth_redirect_uri'));
        if ($configured !== '') {
            return $configured;
        }

        return route('workspace.channels.facebook.callback', absolute: true);
    }

    /**
     * @return list<string>
     */
    public function configuredScopes(): array
    {
        $raw = config('meta.facebook_oauth_scopes');
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($scope) => trim((string) $scope),
                $raw
            )));
        }

        $csv = trim((string) $raw);

        return $csv !== '' ? array_values(array_filter(array_map('trim', explode(',', $csv)))) : [];
    }

    /**
     * @return list<array{id: string, name: string, access_token: string}>
     */
    private function fetchManagedPages(string $userAccessToken): array
    {
        $response = Http::acceptJson()
            ->withToken($userAccessToken)
            ->timeout(20)
            ->get('https://graph.facebook.com/'.$this->graphApiVersion().'/me/accounts', [
                'fields' => 'id,name,access_token',
            ]);

        if ($response->failed()) {
            Log::warning('facebook.oauth.pages_failed', ['http_status' => $response->status()]);

            throw new RuntimeException('Unable to load Facebook Pages for this account.');
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return [];
        }

        $pages = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $token = trim((string) ($row['access_token'] ?? ''));
            if ($id === '' || $token === '') {
                continue;
            }
            $pages[] = [
                'id' => $id,
                'name' => trim((string) ($row['name'] ?? 'Facebook Page')),
                'access_token' => $token,
            ];
        }

        return $pages;
    }

    /**
     * @param  list<array{id: string, name: string, access_token: string}>  $pages
     * @return array{id: string, name: string, access_token: string}
     */
    private function selectPage(array $pages, ?string $selectedPageId): array
    {
        if ($selectedPageId !== null && $selectedPageId !== '') {
            foreach ($pages as $page) {
                if ($page['id'] === $selectedPageId) {
                    return $page;
                }
            }
        }

        if (count($pages) === 1) {
            return $pages[0];
        }

        throw new RuntimeException('Multiple Facebook Pages are available. Reconnect and specify the Page to use.');
    }

    private function exchangeCodeForUserAccessToken(string $code): string
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->get('https://graph.facebook.com/'.$this->graphApiVersion().'/oauth/access_token', [
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook authorization code exchange failed.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException('Facebook authorization did not return an access token.');
        }

        return $token;
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

    private function userBelongsToWorkspace(User $user, Workspace $workspace): bool
    {
        return $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function markWorkspaceFacebookConnected(Workspace $workspace, string $pageName): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $channels = is_array($settings['channels'] ?? null) ? $settings['channels'] : [];
        $channels['facebook_messenger'] = [
            'connected' => true,
            'connected_at' => now()->toISOString(),
            'page_name' => $pageName,
        ];
        $settings['channels'] = $channels;

        $workspace->update(['settings' => $settings]);
    }
}
