<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuthIdentity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceFlutterAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_password_login_with_email_returns_finance_session(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $owner->forceFill(['password' => 'password'])->save();

        $login = $this->postJson('/api/finance/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.finance_enabled', true);

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertTrue((bool) ($login->json('data.permissions')['finance.view'] ?? false));
        $this->assertTrue((bool) ($login->json('data.permissions')['invoices.view'] ?? false));
    }

    public function test_password_login_with_phone_returns_the_same_user(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $owner->forceFill([
            'phone' => '+966500000011',
            'password' => 'password',
        ])->save();

        $this->postJson('/api/finance/v1/auth/login', [
            'phone' => '+966500000011',
            'password' => 'password',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.workspace.id', $workspace->id);
    }

    public function test_invalid_password_is_unauthorized(): void
    {
        [$owner] = $this->createWorkspaceOwner();
        $owner->forceFill(['password' => 'password'])->save();

        $this->postJson('/api/finance/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'unauthorized');
    }

    public function test_google_login_reuses_linked_laravel_user_and_permissions(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        AuthIdentity::query()->create([
            'user_id' => $owner->id,
            'provider' => 'google',
            'provider_user_id' => 'google-linked-1',
            'provider_email' => $owner->email,
        ]);
        $this->fakeGoogleUser($owner->email, $owner->name, 'google-linked-1');

        $login = $this->postJson('/api/finance/v1/auth/google', [
            'access_token' => 'ya29.google-token',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.user.email', $owner->email)
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.finance_enabled', true);

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertTrue((bool) ($login->json('data.permissions')['invoices.view'] ?? false));
        $this->assertSame(1, User::query()->where('email', $owner->email)->count());
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('user_id', $owner->id)->count());
    }

    public function test_google_matching_verified_email_does_not_duplicate_user(): void
    {
        [$owner] = $this->createWorkspaceOwner();
        $this->assertSame(0, AuthIdentity::query()->count());
        $this->fakeGoogleUser($owner->email, $owner->name, 'google-new-identity');

        $this->postJson('/api/finance/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'ya29.google-token',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $owner->id);

        $this->assertSame(1, User::query()->where('email', $owner->email)->count());
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('user_id', $owner->id)->count());
    }

    public function test_invalid_google_credential_is_unauthorized_without_raw_provider_error(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andThrow(new \RuntimeException('invalid_grant from google internals'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->postJson('/api/finance/v1/auth/google', [
            'access_token' => 'bad-token',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonMissing(['message' => 'invalid_grant from google internals']);
    }

    public function test_google_rejects_missing_token_and_non_google_provider(): void
    {
        $this->postJson('/api/finance/v1/auth/google', [])
            ->assertStatus(422);

        $this->postJson('/api/finance/v1/auth/social', [
            'provider' => 'facebook',
            'access_token' => 'token',
        ])->assertStatus(422);
    }

    public function test_google_start_requires_laravel_env_credentials(): void
    {
        Config::set('services.google.client_id', '');
        Config::set('services.google.client_secret', '');

        $this->postJson('/api/finance/v1/auth/google/start')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'يحتاج إعداد Google في ملف .env على Laravel (GOOGLE_CLIENT_ID و GOOGLE_CLIENT_SECRET).'
            );
    }

    public function test_google_browser_ticket_is_the_shared_oauth_flow(): void
    {
        Config::set('services.google.client_id', 'google-client-id');
        Config::set('services.google.client_secret', 'google-client-secret');
        Config::set('services.google.redirect', 'http://localhost/auth/google/callback');
        $this->mockGoogleBrowserDriver('ya29.finance-browser');

        $start = $this->postJson('/api/finance/v1/auth/google/start')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ticket = (string) $start->json('data.ticket');
        $this->assertNotEmpty($ticket);
        $this->assertStringContainsString('accounts.google.com', (string) $start->json('data.auth_url'));

        $this->get('/auth/google/callback?state='.$ticket)
            ->assertOk()
            ->assertSee('حاسم للمالية', false)
            ->assertSee('تم تسجيل الدخول عبر Google', false);

        $this->getJson('/api/finance/v1/auth/google/status?ticket='.$ticket)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.access_token', 'ya29.finance-browser');
    }

    public function test_multiple_workspaces_are_returned_for_selection(): void
    {
        [$owner, $first] = $this->createWorkspaceOwner('Company A');
        $second = $this->attachSecondWorkspace($owner, 'Company B');
        $owner->forceFill(['password' => 'password'])->save();

        $login = $this->postJson('/api/finance/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
        ])->assertOk();

        $ids = collect($login->json('data.workspaces'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$first->id, $second->id])->sort()->values()->all(), $ids);
    }

    public function test_finance_disabled_workspace_still_authenticates_with_flag_false(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance', 'enabled' => false, 'source' => 'manual']
        );
        $owner->forceFill(['password' => 'password'])->save();

        $this->postJson('/api/finance/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.finance_enabled', false)
            ->assertJsonPath('data.workspace.finance_enabled', false);

        $this->fakeGoogleUser($owner->email, $owner->name, 'google-disabled-finance');
        $this->postJson('/api/finance/v1/auth/google', [
            'access_token' => 'ya29.google-token',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.finance_enabled', false);
    }

    public function test_logout_revokes_token_and_me_is_unauthorized(): void
    {
        [$owner] = $this->createWorkspaceOwner();
        $owner->forceFill(['password' => 'password'])->save();

        $token = $this->postJson('/api/finance/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $headers = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/auth/logout')
            ->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_unauthenticated_me_is_unauthorized(): void
    {
        $this->getJson('/api/finance/v1/auth/me')->assertStatus(401);
    }

    public function test_forgot_password_sends_reset_link_for_existing_user(): void
    {
        [$owner] = $this->createWorkspaceOwner();

        $this->postJson('/api/finance/v1/auth/forgot-password', [
            'email' => $owner->email,
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_reset_password_with_valid_token(): void
    {
        [$owner] = $this->createWorkspaceOwner();
        $token = Password::broker()->createToken($owner);

        $this->postJson('/api/finance/v1/auth/reset-password', [
            'email' => $owner->email,
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewPassword123!', $owner->fresh()->password));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Auth Co'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'pos', 'products', 'orders', 'customers', 'payments', 'payment_gateway'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->orderByDesc('price')->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    private function attachSecondWorkspace(User $user, string $name): Workspace
    {
        app(WorkspaceContext::class)->clear();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        foreach (['finance'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }
        app(WorkspaceContext::class)->set($workspace);

        return $workspace;
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
}
