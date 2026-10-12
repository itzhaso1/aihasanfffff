<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Models\WorkspaceTelegramConnection;
use App\Models\WorkspaceTikTokConnection;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Meta\MetaOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileChannelConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.meta_app_id', 'test-meta-app-id');
        config()->set('whatsapp.meta_app_secret', 'test-meta-app-secret');
        config()->set('whatsapp.embedded_signup_config_id', 'test-config-id');
        config()->set('whatsapp.api_version', 'v20.0');
        config()->set('meta.app_id', 'test-meta-app-id');
        config()->set('meta.app_secret', 'test-meta-app-secret');
        config()->set('meta.graph_api_version', 'v20.0');
        config()->set('meta.facebook_oauth_scopes', 'pages_messaging,pages_show_list');
        config()->set('meta.instagram_oauth_scopes', 'instagram_business_basic,instagram_business_manage_messages');
        config()->set('meta.instagram_oauth_redirect_uri', null);
        config()->set('meta.facebook_oauth_redirect_uri', null);
        config()->set('tiktok.client_key', 'tt-key');
        config()->set('tiktok.client_secret', 'tt-secret');
        config()->set('tiktok.redirect_uri', 'https://example.test/workspace/channels/tiktok/callback');
        config()->set('tiktok.oauth_scopes', ['business.messaging']);
        config()->set('tiktok.oauth_authorize_url', 'https://www.tiktok.com/v2/auth/authorize');
        config()->set('channel_connect.mobile_scheme', 'hasim');
    }

    public function test_channels_index_includes_native_connect_flags_without_secrets(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        $response = $this->asMember($owner, $workspace)
            ->getJson('/api/mobile/v1/channels')
            ->assertOk()
            ->assertJsonPath('success', true);

        $keys = collect($response->json('data'))->pluck('key')->all();
        $this->assertContains('whatsapp', $keys);
        $this->assertContains('facebook_messenger', $keys);
        $this->assertContains('instagram', $keys);
        $this->assertContains('tiktok', $keys);
        $this->assertContains('telegram', $keys);

        $encoded = json_encode($response->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('test-meta-app-secret', $encoded);
        $this->assertStringNotContainsString('tt-secret', $encoded);
        $this->assertStringNotContainsString('client_secret', $encoded);
        $this->assertStringNotContainsString('access_token', $encoded);
    }

    public function test_owner_can_start_whatsapp_embedded_signup_without_secrets(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        $response = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/whatsapp/connect')
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.mode', 'embedded_signup')
            ->assertJsonPath('data.callback_scheme', 'hasim');

        $url = $response->json('data.authorization_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/mobile/channel-connect/whatsapp/', $url);
        $this->assertArrayNotHasKey('client_secret', $response->json('data'));
        $this->assertStringNotContainsString('test-meta-app-secret', (string) json_encode($response->json()));

        $this->get($url)
            ->assertOk()
            ->assertSee('test-meta-app-id')
            ->assertSee('test-config-id')
            ->assertDontSee('test-meta-app-secret');
    }

    public function test_whatsapp_mobile_signup_completes_and_redirects_to_app(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $start = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/whatsapp/connect')
            ->assertOk();

        $url = (string) $start->json('data.authorization_url');
        $token = basename(parse_url($url, PHP_URL_PATH) ?: '');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['access_token' => 'wa-user-token', 'id' => 'waba_1', 'name' => 'Hasim WABA', 'data' => [[
                'id' => 'phone_1',
                'display_phone_number' => '+966500000001',
                'verified_name' => 'Hasim',
            ]]], 200),
        ]);

        $complete = $this->postJson('/mobile/channel-connect/whatsapp/'.$token, [
            'code' => 'wa-code',
            'session_info' => [
                'waba_id' => 'waba_1',
                'phone_number_id' => 'phone_1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertStringStartsWith('hasim://channels/oauth', (string) $complete->json('redirect'));
        $this->assertSame(1, WhatsAppAccount::withoutGlobalScopes()->count());
        $account = WhatsAppAccount::withoutGlobalScopes()->first();
        $this->assertSame($workspace->id, $account?->workspace_id);
        $this->assertSame('connected', $account?->status);
        $this->assertStringNotContainsString('wa-user-token', (string) json_encode($account?->toArray()));
    }

    public function test_whatsapp_callback_rejects_replayed_token(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $start = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/whatsapp/connect')
            ->assertOk();
        $token = basename(parse_url((string) $start->json('data.authorization_url'), PHP_URL_PATH) ?: '');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['access_token' => 'wa-user-token', 'id' => 'waba_1', 'name' => 'Hasim WABA', 'data' => [[
                'id' => 'phone_1',
                'display_phone_number' => '+9665',
                'verified_name' => 'Hasim',
            ]]], 200),
        ]);

        $this->postJson('/mobile/channel-connect/whatsapp/'.$token, [
            'code' => 'wa-code',
            'session_info' => ['waba_id' => 'waba_1', 'phone_number_id' => 'phone_1'],
        ])->assertOk();

        $this->postJson('/mobile/channel-connect/whatsapp/'.$token, [
            'code' => 'wa-code',
            'session_info' => ['waba_id' => 'waba_1', 'phone_number_id' => 'phone_1'],
        ])->assertStatus(410);
    }

    public function test_owner_can_start_facebook_instagram_and_tiktok_oauth(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        $facebook = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/facebook_messenger/connect')
            ->assertOk();
        $this->assertStringStartsWith('https://www.facebook.com/', (string) $facebook->json('data.authorization_url'));
        $this->assertStringNotContainsString('test-meta-app-secret', (string) json_encode($facebook->json()));

        $instagram = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/instagram/connect')
            ->assertOk();
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize', (string) $instagram->json('data.authorization_url'));

        $tiktok = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/tiktok/connect')
            ->assertOk();
        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize', (string) $tiktok->json('data.authorization_url'));
        $this->assertStringNotContainsString('tt-secret', (string) json_encode($tiktok->json()));
    }

    public function test_mobile_instagram_callback_completes_without_web_session(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        $this->fakeInstagramSuccess();

        $response = $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]));

        $response->assertRedirect();
        $this->assertStringStartsWith('hasim://channels/oauth', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('status=success', (string) $response->headers->get('Location'));
        $this->assertSame(1, WorkspaceMetaConnection::withoutGlobalScopes()->count());
        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->first();
        $this->assertSame($workspace->id, $connection?->workspace_id);
        $this->assertSame('long-lived-instagram-user-token', $connection?->accessToken());
        $this->assertArrayNotHasKey('access_token_ciphertext', $connection?->toArray() ?? []);
    }

    public function test_mobile_facebook_callback_connects_single_page_without_session(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_FACEBOOK,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        Http::fake([
            'https://graph.facebook.com/*oauth/access_token*' => Http::response(['access_token' => 'fb-user-token'], 200),
            'https://graph.facebook.com/*me/accounts*' => Http::response([
                'data' => [['id' => 'page_1', 'name' => 'Hasim Page', 'access_token' => 'page-token']],
            ], 200),
        ]);

        $this->get(route('workspace.channels.facebook.callback', [
            'code' => 'fb-code',
            'state' => $state,
        ]))
            ->assertRedirect()
            ->assertRedirectContains('hasim://channels/oauth');

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->first();
        $this->assertSame(MetaConnectionKind::FACEBOOK_PAGE, $connection?->kind);
        $this->assertSame('page-token', $connection?->accessToken());
    }

    public function test_facebook_multiple_pages_require_mobile_selection_without_exposing_tokens(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_FACEBOOK,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        Http::fake([
            'https://graph.facebook.com/*oauth/access_token*' => Http::response(['access_token' => 'fb-user-token'], 200),
            'https://graph.facebook.com/*me/accounts*' => Http::response([
                'data' => [
                    ['id' => 'page_1', 'name' => 'Page One', 'access_token' => 'secret-page-one'],
                    ['id' => 'page_2', 'name' => 'Page Two', 'access_token' => 'secret-page-two'],
                ],
            ], 200),
        ]);

        $location = (string) $this->get(route('workspace.channels.facebook.callback', [
            'code' => 'fb-code',
            'state' => $state,
        ]))->headers->get('Location');

        $this->assertStringContainsString('status=select_page', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $attempt = (string) ($query['attempt'] ?? '');
        $this->assertNotSame('', $attempt);

        $pages = $this->asMember($owner, $workspace)
            ->getJson('/api/mobile/v1/channels/facebook_messenger/pages?attempt='.$attempt)
            ->assertOk()
            ->json('data.pages');

        $this->assertSame(['page_1', 'page_2'], array_column($pages, 'id'));
        $encoded = json_encode($pages);
        $this->assertStringNotContainsString('secret-page-one', (string) $encoded);
        $this->assertStringNotContainsString('access_token', (string) $encoded);

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/facebook_messenger/pages', [
                'attempt' => $attempt,
                'page_id' => 'page_2',
            ])
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.display_name', 'Page Two');
    }

    public function test_mobile_tiktok_callback_persists_encrypted_tokens(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_TIKTOK,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        Http::fake([
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'data' => [
                    'open_id' => 'tt-open-1',
                    'access_token' => 'tt-access',
                    'refresh_token' => 'tt-refresh',
                    'expires_in' => 3600,
                    'display_name' => 'TikTok Shop',
                    'scope' => ['business.messaging'],
                ],
            ], 200),
        ]);

        $this->get(route('workspace.channels.tiktok.callback', [
            'code' => 'tt-code',
            'state' => $state,
        ]))->assertRedirectContains('hasim://channels/oauth');

        $connection = WorkspaceTikTokConnection::withoutGlobalScopes()->first();
        $this->assertSame($workspace->id, $connection?->workspace_id);
        $this->assertSame('tt-access', $connection?->accessToken());
        $this->assertArrayNotHasKey('access_token_ciphertext', $connection?->toArray() ?? []);
        $this->assertArrayNotHasKey('refresh_token_ciphertext', $connection?->toArray() ?? []);
    }

    public function test_telegram_connects_with_bot_token_and_does_not_echo_it(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        Http::fake([
            'api.telegram.org/*getMe*' => Http::response([
                'ok' => true,
                'result' => ['id' => 555, 'username' => 'hasim_bot', 'first_name' => 'Hasim'],
            ], 200),
            'api.telegram.org/*setWebhook*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $response = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/telegram/connect', [
                'bot_token' => '123456:secret-bot-token',
            ])
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.display_name', '@hasim_bot');

        $this->assertStringNotContainsString('123456:secret-bot-token', (string) json_encode($response->json()));
        $connection = WorkspaceTelegramConnection::withoutGlobalScopes()->first();
        $this->assertSame('123456:secret-bot-token', $connection?->botToken());
        $this->assertArrayNotHasKey('bot_token_ciphertext', $connection?->toArray() ?? []);
    }

    public function test_invalid_oauth_state_is_rejected(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        Http::fake();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'dummy',
                'state' => 'not-valid',
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_callback_from_another_user_is_rejected(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $intruder = User::factory()->create();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        $this->fakeInstagramSuccess();

        $this->actingAs($intruder)
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'auth-code-123',
                'state' => $state,
            ]))
            ->assertRedirectContains('hasim://channels/oauth');

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->first();
        $this->assertNotNull($connection);
        $this->assertSame($workspace->id, $connection->workspace_id);
        $this->assertSame($owner->id, $connection->connected_by_user_id);
    }

    public function test_web_callback_rejects_other_session_user(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $intruder = User::factory()->create();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_WEB,
        )['state'];

        $this->fakeInstagramSuccess();

        $this->actingAs($intruder)
            ->get(route('workspace.channels.instagram.callback', [
                'code' => 'auth-code-123',
                'state' => $state,
            ]))
            ->assertRedirect(route('workspace.channels.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_workspace_b_cannot_finish_workspace_a_facebook_page_selection(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWorkspace();
        [$ownerB, $workspaceB] = $this->ownerWorkspace();
        $state = app(MetaOAuthState::class)->issue(
            $workspaceA->id,
            $ownerA->id,
            MetaOAuthState::PURPOSE_FACEBOOK,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        Http::fake([
            'https://graph.facebook.com/*oauth/access_token*' => Http::response(['access_token' => 'fb-user-token'], 200),
            'https://graph.facebook.com/*me/accounts*' => Http::response([
                'data' => [
                    ['id' => 'page_1', 'name' => 'Page One', 'access_token' => 'secret-page-one'],
                    ['id' => 'page_2', 'name' => 'Page Two', 'access_token' => 'secret-page-two'],
                ],
            ], 200),
        ]);

        $location = (string) $this->get(route('workspace.channels.facebook.callback', [
            'code' => 'fb-code',
            'state' => $state,
        ]))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $attempt = (string) ($query['attempt'] ?? '');

        $this->asMember($ownerB, $workspaceB)
            ->getJson('/api/mobile/v1/channels/facebook_messenger/pages?attempt='.$attempt)
            ->assertStatus(422);

        $this->asMember($ownerB, $workspaceB)
            ->postJson('/api/mobile/v1/channels/facebook_messenger/pages', [
                'attempt' => $attempt,
                'page_id' => 'page_1',
            ])
            ->assertStatus(422);
    }

    public function test_regular_member_cannot_start_or_disconnect(): void
    {
        [, $workspace] = $this->ownerWorkspace();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->asMember($member, $workspace)
            ->postJson('/api/mobile/v1/channels/instagram/connect')
            ->assertForbidden();

        $this->asMember($member, $workspace)
            ->postJson('/api/mobile/v1/channels/instagram/disconnect')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_start_connection(): void
    {
        $this->postJson('/api/mobile/v1/channels/whatsapp/connect')->assertUnauthorized();
    }

    public function test_independent_worker_cannot_manage_company_channels(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $worker = User::factory()->create();
        WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'Worker',
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'nationality' => 'Saudi',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);

        Sanctum::actingAs($worker);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/channels')
            ->assertForbidden();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/channels/whatsapp/connect')
            ->assertForbidden();

        $this->assertSame($owner->id, $workspace->owner_user_id);
    }

    public function test_instagram_mobile_callback_rejects_replayed_state(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();
        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];

        $this->fakeInstagramSuccess();
        $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]))->assertRedirectContains('status=success');

        $this->fakeInstagramSuccess();
        $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]))->assertRedirectContains('status=error');

        $this->assertSame(1, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    public function test_disconnect_whatsapp_instagram_and_tiktok_then_reconnect_instagram(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['access_token' => 'wa-user-token', 'id' => 'waba_1', 'name' => 'Hasim WABA', 'data' => [[
                'id' => 'phone_1',
                'display_phone_number' => '+966500000001',
                'verified_name' => 'Hasim',
            ]]], 200),
            'api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'short-lived-instagram-user-token',
                'user_id' => '102078776356097',
                'permissions' => 'instagram_business_basic,instagram_business_manage_messages',
            ], 200),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'long-lived-instagram-user-token',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ], 200),
            'graph.instagram.com/v20.0/me*' => Http::response([
                'user_id' => '17841400000000001',
                'username' => 'hasim.test',
                'name' => 'Hasim Test',
                'account_type' => 'Business',
            ], 200),
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'data' => [
                    'open_id' => 'tt-open-1',
                    'access_token' => 'tt-access',
                    'refresh_token' => 'tt-refresh',
                    'expires_in' => 3600,
                    'display_name' => 'TikTok Shop',
                    'scope' => ['business.messaging'],
                ],
            ], 200),
        ]);

        $whatsappStart = $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/whatsapp/connect')
            ->assertOk();
        $token = basename(parse_url((string) $whatsappStart->json('data.authorization_url'), PHP_URL_PATH) ?: '');
        $this->postJson('/mobile/channel-connect/whatsapp/'.$token, [
            'code' => 'wa-code',
            'session_info' => ['waba_id' => 'waba_1', 'phone_number_id' => 'phone_1'],
        ])->assertOk();

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/whatsapp/disconnect')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
        $this->assertSame('disconnected', WhatsAppAccount::withoutGlobalScopes()->first()?->status);

        $instagramState = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];
        $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'ig-code',
            'state' => $instagramState,
        ]))->assertRedirectContains('status=success');

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/instagram/disconnect')
            ->assertOk();
        $this->assertSame('disconnected', WorkspaceMetaConnection::withoutGlobalScopes()->first()?->status);

        $reconnectState = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];
        $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'ig-code-2',
            'state' => $reconnectState,
        ]))->assertRedirectContains('status=success');
        $this->assertSame('connected', WorkspaceMetaConnection::withoutGlobalScopes()->first()?->status);

        $tiktokState = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_TIKTOK,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];
        $this->get(route('workspace.channels.tiktok.callback', [
            'code' => 'tt-code',
            'state' => $tiktokState,
        ]))->assertRedirectContains('hasim://channels/oauth');

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/tiktok/disconnect')
            ->assertOk();
        $this->assertSame(
            WorkspaceTikTokConnection::STATUS_REVOKED,
            WorkspaceTikTokConnection::withoutGlobalScopes()->first()?->status,
        );
    }

    public function test_disconnect_and_reconnect_facebook_and_telegram(): void
    {
        [$owner, $workspace] = $this->ownerWorkspace();

        Http::fake([
            'https://graph.facebook.com/*oauth/access_token*' => Http::response(['access_token' => 'fb-user-token'], 200),
            'https://graph.facebook.com/*me/accounts*' => Http::response([
                'data' => [['id' => 'page_1', 'name' => 'Hasim Page', 'access_token' => 'page-token']],
            ], 200),
            'api.telegram.org/*getMe*' => Http::response([
                'ok' => true,
                'result' => ['id' => 555, 'username' => 'hasim_bot', 'first_name' => 'Hasim'],
            ], 200),
            'api.telegram.org/*setWebhook*' => Http::response(['ok' => true, 'result' => true], 200),
            'api.telegram.org/*deleteWebhook*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $state = app(MetaOAuthState::class)->issue(
            $workspace->id,
            $owner->id,
            MetaOAuthState::PURPOSE_FACEBOOK,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];
        $this->get(route('workspace.channels.facebook.callback', [
            'code' => 'fb-code',
            'state' => $state,
        ]))->assertRedirect();

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/facebook_messenger/disconnect')
            ->assertOk()
            ->assertJsonPath('data.connected', false);

        $this->assertSame('disconnected', WorkspaceMetaConnection::withoutGlobalScopes()->first()?->status);

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/telegram/connect', [
                'bot_token' => '123456:secret-bot-token',
            ])
            ->assertOk();

        $this->asMember($owner, $workspace)
            ->postJson('/api/mobile/v1/channels/telegram/disconnect')
            ->assertOk();

        $this->assertSame(
            WorkspaceTelegramConnection::STATUS_DISCONNECTED,
            WorkspaceTelegramConnection::withoutGlobalScopes()->first()?->status,
        );
    }

    public function test_instagram_account_cannot_bind_to_another_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWorkspace();
        [$ownerB, $workspaceB] = $this->ownerWorkspace();

        $stateA = app(MetaOAuthState::class)->issue(
            $workspaceA->id,
            $ownerA->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];
        $this->fakeInstagramSuccess();
        $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'code-a',
            'state' => $stateA,
        ]))->assertRedirect();

        $stateB = app(MetaOAuthState::class)->issue(
            $workspaceB->id,
            $ownerB->id,
            MetaOAuthState::PURPOSE_INSTAGRAM,
            MetaOAuthState::RETURN_MOBILE,
        )['state'];
        $this->fakeInstagramSuccess();
        $this->get(route('workspace.channels.instagram.callback', [
            'code' => 'code-b',
            'state' => $stateB,
        ]))->assertRedirectContains('status=error');

        $this->assertSame(1, WorkspaceMetaConnection::withoutGlobalScopes()->count());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableWorkspaceFeature($workspace, 'whatsapp');

        return [$user, $workspace];
    }

    private function asMember(User $user, Workspace $workspace): self
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Workspace-Id', (string) $workspace->id);
    }

    private function fakeInstagramSuccess(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'short-lived-instagram-user-token',
                'user_id' => '102078776356097',
                'permissions' => 'instagram_business_basic,instagram_business_manage_messages',
            ], 200),
            'graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'long-lived-instagram-user-token',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ], 200),
            'graph.instagram.com/v20.0/me*' => Http::response([
                'user_id' => '17841400000000001',
                'username' => 'hasim.test',
                'name' => 'Hasim Test',
                'account_type' => 'Business',
            ], 200),
        ]);
    }
}
