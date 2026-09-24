<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\AuthIdentity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class CashierGoogleSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_social_login_returns_the_same_cashier_envelope(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $this->fakeGoogleUser($owner->email, $owner->name, 'google-user-1');

        $login = $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'ya29.google-token',
            'device_name' => 'كاشير حاسم',
            'device_type' => 'cashier',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.user.email', $owner->email)
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.id', $workspace->id)
            ->assertJsonPath('data.workspaces.0.pos_enabled', true)
            ->assertJsonPath('data.pos_enabled', true);

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->count());
    }

    public function test_new_google_user_is_provisioned_with_unusable_password(): void
    {
        $this->seed(FoundationSeeder::class);
        $this->fakeGoogleUser('new-oauth@hasim.test', 'New OAuth User', 'google-new-cashier-1');

        $login = $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'ya29.google-token',
            'device_name' => 'كاشير حاسم',
            'device_type' => 'cashier',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'new-oauth@hasim.test');

        $user = User::query()->where('email', 'new-oauth@hasim.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->password);
        $this->assertNotSame('', $user->password);
        $this->assertFalse(Hash::needsRehash($user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('new-oauth@hasim.test', $user->password));
        $this->assertFalse(Hash::check('google-new-cashier-1', $user->password));
        $this->assertFalse(Hash::check('ya29.google-token', $user->password));
        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame($user->id, $login->json('data.user.id'));
        $this->assertSame($user->id, Workspace::query()->find($login->json('data.workspace.id'))?->owner_user_id);
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('provider_user_id', 'google-new-cashier-1')->count());
    }

    public function test_repeat_google_social_login_does_not_duplicate_user(): void
    {
        $this->seed(FoundationSeeder::class);
        $this->fakeGoogleUser('repeat-oauth@hasim.test', 'Repeat OAuth User', 'google-repeat-cashier-1');

        $first = $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'ya29.google-token',
            'device_type' => 'cashier',
        ])->assertOk();

        $second = $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'ya29.google-token',
            'device_type' => 'cashier',
        ])->assertOk();

        $this->assertSame($first->json('data.user.id'), $second->json('data.user.id'));
        $this->assertSame(1, User::query()->where('email', 'repeat-oauth@hasim.test')->count());
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('provider_user_id', 'google-repeat-cashier-1')->count());
    }

    public function test_oauth_user_cannot_login_with_guessable_password_secrets(): void
    {
        $this->seed(FoundationSeeder::class);
        $this->fakeGoogleUser('oauth-only-cashier@hasim.test', 'OAuth Only Cashier', 'google-cashier-no-bypass');

        $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'ya29.google-token',
            'device_type' => 'cashier',
        ])->assertOk();

        foreach (['password', 'oauth-only-cashier@hasim.test', 'google-cashier-no-bypass', 'ya29.google-token'] as $guess) {
            $this->postJson('/api/cashier/v1/auth/login', [
                'email' => 'oauth-only-cashier@hasim.test',
                'password' => $guess,
                'device_type' => 'cashier',
            ])->assertStatus(401);
        }
    }

    public function test_google_social_login_rejects_missing_token(): void
    {
        $this->seed(FoundationSeeder::class);

        $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'google',
            'device_name' => 'كاشير حاسم',
        ])
            ->assertStatus(422);
    }

    public function test_google_social_login_rejects_invalid_provider(): void
    {
        $this->seed(FoundationSeeder::class);

        $this->postJson('/api/cashier/v1/auth/social', [
            'provider' => 'twitter',
            'access_token' => 'token',
        ])
            ->assertStatus(422);
    }

    public function test_google_start_requires_laravel_env_credentials(): void
    {
        $this->seed(FoundationSeeder::class);
        Config::set('services.google.client_id', '');
        Config::set('services.google.client_secret', '');

        $this->postJson('/api/cashier/v1/auth/google/start')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'يحتاج إعداد Google في ملف .env على Laravel (GOOGLE_CLIENT_ID و GOOGLE_CLIENT_SECRET).'
            );
    }

    public function test_google_browser_ticket_returns_access_token_for_social_login(): void
    {
        $this->seed(FoundationSeeder::class);
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost/auth/google/callback');

        $this->mockGoogleBrowserDriver('ya29.from-laravel-env');

        $start = $this->postJson('/api/cashier/v1/auth/google/start')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ticket = (string) $start->json('data.ticket');
        $this->assertNotEmpty($ticket);
        $this->assertStringContainsString(
            'accounts.google.com',
            (string) $start->json('data.auth_url')
        );

        $this->get('/auth/google/callback?state='.$ticket)
            ->assertOk()
            ->assertSee('كاشير حاسم', false);

        $status = $this->getJson('/api/cashier/v1/auth/google/status?ticket='.$ticket)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.access_token', 'ya29.from-laravel-env');

        $this->assertSame('ya29.from-laravel-env', $status->json('data.access_token'));

        $this->getJson('/api/cashier/v1/auth/google/status?ticket='.$ticket)
            ->assertStatus(404);
    }

    public function test_google_cashier_callback_completes_when_website_session_exists(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner] = $this->createWorkspaceOwner('store');
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost/auth/google/callback');

        $this->mockGoogleBrowserDriver('ya29.logged-in-browser');

        $ticket = (string) $this->postJson('/api/cashier/v1/auth/google/start')
            ->assertOk()
            ->json('data.ticket');

        $this->actingAs($owner)
            ->get('/auth/google/callback?state='.$ticket)
            ->assertOk()
            ->assertSee('تم تسجيل الدخول عبر Google', false);

        $this->getJson('/api/cashier/v1/auth/google/status?ticket='.$ticket)
            ->assertOk()
            ->assertJsonPath('data.access_token', 'ya29.logged-in-browser');
    }

    public function test_website_google_callback_redirects_when_already_authenticated(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner] = $this->createWorkspaceOwner('store');

        $this->actingAs($owner)
            ->get('/auth/google/callback?state=not-a-cashier-ticket')
            ->assertRedirect(route('workspace.choose'));
    }

    public function test_google_status_expires_unknown_ticket(): void
    {
        $this->seed(FoundationSeeder::class);
        $this->getJson('/api/cashier/v1/auth/google/status?ticket=11111111-1111-1111-1111-111111111111')
            ->assertStatus(404);
    }

    public function test_google_cashier_callback_skips_session_state_when_cache_misses(): void
    {
        $this->seed(FoundationSeeder::class);
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost/auth/google/callback');

        $this->mockGoogleBrowserDriver('ya29.cache-miss');
        $ticket = '22222222-2222-4222-8222-222222222222';

        $this->get('/auth/google/callback?state='.$ticket.'&code=oauth-code')
            ->assertOk()
            ->assertSee('تم تسجيل الدخول عبر Google', false);

        $this->getJson('/api/cashier/v1/auth/google/status?ticket='.$ticket)
            ->assertOk()
            ->assertJsonPath('data.access_token', 'ya29.cache-miss');
    }

    public function test_google_cashier_callback_accepts_prefixed_oauth_state(): void
    {
        $this->seed(FoundationSeeder::class);
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost/auth/google/callback');

        $this->mockGoogleBrowserDriver('ya29.prefixed-state');
        $ticket = (string) $this->postJson('/api/cashier/v1/auth/google/start')
            ->assertOk()
            ->json('data.ticket');

        $this->get('/auth/google/callback?state=cashier.'.$ticket.'&code=oauth-code')
            ->assertOk()
            ->assertSee('تم تسجيل الدخول عبر Google', false);

        $this->getJson('/api/cashier/v1/auth/google/status?ticket='.$ticket)
            ->assertOk()
            ->assertJsonPath('data.access_token', 'ya29.prefixed-state');
    }

    public function test_website_google_callback_uses_stateless_and_does_not_throw_invalid_state(): void
    {
        $this->seed(FoundationSeeder::class);
        $this->fakeWebsiteGoogleUser('web-google-1', 'owner@hasim.test', 'Owner');

        $this->get('/auth/google/callback?code=oauth-code&state='.str_repeat('a', 40))
            ->assertRedirect(route('workspace.choose'));

        $this->assertAuthenticated();
        $user = User::query()->where('email', 'owner@hasim.test')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->password);
        $this->assertFalse(Hash::needsRehash($user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('owner@hasim.test', $user->password));
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('provider_user_id', 'web-google-1')->count());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();

        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        return [$user, $workspace];
    }

    private function mockGoogleBrowserDriver(string $accessToken): void
    {
        $redirect = Mockery::mock();
        $redirect->shouldReceive('getTargetUrl')->andReturn(
            'https://accounts.google.com/o/oauth2/v2/auth?client_id=google-client-id'
        );

        $socialUser = new SocialiteUser;
        $socialUser->map([
            'id' => 'google-user-browser',
            'name' => 'Google User',
            'email' => 'google@hasim.test',
        ]);
        $socialUser->token = $accessToken;

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn($redirect);
        $driver->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function fakeWebsiteGoogleUser(string $googleId, string $email, string $name): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($googleId);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getNickname')->andReturnNull();
        $socialUser->shouldReceive('getAvatar')->andReturnNull();

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function fakeGoogleUser(string $email, string $name, string $googleId): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($googleId);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getNickname')->andReturnNull();
        $socialUser->shouldReceive('getAvatar')->andReturnNull();

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->with('ya29.google-token')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}
